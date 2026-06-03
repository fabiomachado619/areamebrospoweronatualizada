/** Formatos de bloco de imagem do checkout (proporções fixas). */

export const IMAGE_FORMATS = {
    hero: {
        id: 'hero',
        label: 'Hero (topo)',
        ratioLabel: '3:1',
        recommendedSize: '1200×400 px',
        aspectClass: 'aspect-[3/1]',
        placement: 'main',
    },
    wide: {
        id: 'wide',
        label: 'Largo (16:9)',
        ratioLabel: '16:9',
        recommendedSize: '1280×720 px',
        aspectClass: 'aspect-video',
        placement: 'main',
    },
    portrait: {
        id: 'portrait',
        label: 'Retrato (lateral)',
        ratioLabel: '2:3',
        recommendedSize: '400×600 px',
        aspectClass: 'aspect-[2/3]',
        placement: 'sidebar',
    },
    square: {
        id: 'square',
        label: 'Quadrado',
        ratioLabel: '1:1',
        recommendedSize: '800×800 px',
        aspectClass: 'aspect-square',
        placement: 'main',
    },
};

export const SALES_PAGE_FORMATS = ['wide', 'square'];
export const HERO_FORMAT = 'hero';
export const SIDEBAR_FORMAT = 'portrait';

export const TEXT_ALIGNS = [
    { id: 'left', label: 'Esquerda' },
    { id: 'center', label: 'Centro' },
    { id: 'right', label: 'Direita' },
];

/**
 * @param {string} formatId
 * @returns {{ aspectClass: string, recommendedSize: string, label: string, ratioLabel: string }}
 */
export function getImageFormat(formatId) {
    return IMAGE_FORMATS[formatId] ?? IMAGE_FORMATS.wide;
}

/**
 * @param {string} formatId
 * @returns {number} aspect ratio width/height
 */
export function getFormatRatio(formatId) {
    const ratios = {
        hero: 3 / 1,
        wide: 16 / 9,
        portrait: 2 / 3,
        square: 1,
    };

    return ratios[formatId] ?? 16 / 9;
}

function generateBlockId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return `block-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/**
 * Normaliza um bloco bruto (preserva rascunhos vazios — uso no editor).
 * @param {unknown} block
 * @returns {object|null}
 */
export function parseContentBlockForEditor(block) {
    if (typeof block === 'string' && block.trim()) {
        return {
            id: generateBlockId(),
            type: 'image',
            url: block.trim(),
            format: 'wide',
            placement: 'main',
            link: '',
            alt: '',
        };
    }

    if (!block || typeof block !== 'object') {
        return null;
    }

    if (block.type === 'text') {
        return {
            id: block.id || generateBlockId(),
            type: 'text',
            title: String(block.title ?? ''),
            body: String(block.body ?? ''),
            align: ['left', 'center', 'right'].includes(block.align) ? block.align : 'center',
        };
    }

    const format = IMAGE_FORMATS[block.format] ? block.format : 'wide';

    return {
        id: block.id || generateBlockId(),
        type: 'image',
        url: String(block.url ?? ''),
        format,
        placement: block.placement === 'sidebar' ? 'sidebar' : 'main',
        link: String(block.link ?? ''),
        alt: String(block.alt ?? ''),
    };
}

/**
 * Normaliza blocos para o editor (mantém blocos vazios recém-adicionados).
 * @param {unknown} blocks
 * @returns {Array<object>}
 */
export function normalizeContentBlocksForEditor(blocks) {
    if (!Array.isArray(blocks)) {
        return [];
    }

    return blocks.map((block) => parseContentBlockForEditor(block)).filter(Boolean);
}

/**
 * Normaliza blocos para preview ao vivo no Builder (ignora vazios, mantém rascunhos parciais).
 * @param {unknown} blocks
 * @returns {Array<object>}
 */
export function normalizeContentBlocksForPreview(blocks) {
    if (!Array.isArray(blocks)) {
        return [];
    }

    return dedupeContentBlocks(
        blocks
            .map((block) => {
            const parsed = parseContentBlockForEditor(block);
            if (!parsed) {
                return null;
            }
            if (parsed.type === 'text') {
                const title = String(parsed.title ?? '');
                const body = String(parsed.body ?? '');
                if (!title.trim() && !body.trim()) {
                    return null;
                }

                return { ...parsed, title, body };
            }
            const url = String(parsed.url ?? '').trim();
            if (!url) {
                return null;
            }

            return { ...parsed, url };
        })
        .filter(Boolean)
    );
}

/**
 * Normaliza blocos legacy (strings) para objetos — checkout público (ignora vazios).
 * @param {unknown} blocks
 * @returns {Array<object>}
 */
export function normalizeContentBlocks(blocks) {
    if (!Array.isArray(blocks)) {
        return [];
    }

    return dedupeContentBlocks(
        blocks
            .map((block) => {
            const parsed = parseContentBlockForEditor(block);
            if (!parsed) {
                return null;
            }
            if (parsed.type === 'text') {
                const title = String(parsed.title ?? '').trim();
                const body = String(parsed.body ?? '').trim();
                if (!title && !body) {
                    return null;
                }

                return { ...parsed, title, body };
            }
            const url = String(parsed.url ?? '').trim();
            if (!url) {
                return null;
            }

            return { ...parsed, url };
        })
        .filter(Boolean)
    );
}

/** @param {Array<object>} blocks @returns {Array<object>} */
export function dedupeContentBlocks(blocks) {
    if (!Array.isArray(blocks)) {
        return [];
    }

    const seen = new Set();

    return blocks.filter((block) => {
        const id = block?.id;
        if (id) {
            if (seen.has(id)) {
                return false;
            }
            seen.add(id);
        }

        return true;
    });
}

/**
 * @param {Array<object>} blocks
 * @param {{ placement?: string, format?: string, excludeFormat?: string, type?: string }} filter
 */
export function filterContentBlocks(blocks, filter = {}) {
    const list = normalizeContentBlocks(blocks);

    return list.filter((block) => {
        if (filter.type && block.type !== filter.type) {
            return false;
        }
        if (filter.placement) {
            const placement = block.type === 'text' ? 'main' : (block.placement ?? 'main');
            if (placement !== filter.placement) {
                return false;
            }
        }
        if (filter.format) {
            if (block.type !== 'image' || block.format !== filter.format) {
                return false;
            }
        }
        if (filter.excludeFormat && block.type === 'image' && block.format === filter.excludeFormat) {
            return false;
        }
        return true;
    });
}

export function newImageBlock(format = 'wide', placement = 'main') {
    return {
        id: generateBlockId(),
        type: 'image',
        url: '',
        format,
        placement,
        link: '',
        alt: '',
    };
}

export function newTextBlock() {
    return {
        id: generateBlockId(),
        type: 'text',
        title: '',
        body: '',
        align: 'center',
    };
}

/**
 * Inicializa content_blocks a partir de appearance (legacy banners ou content_blocks).
 * @param {object|null|undefined} appearance
 */
export function initContentBlocksFromAppearance(appearance) {
    if (!appearance || typeof appearance !== 'object') {
        return [];
    }
    if (Array.isArray(appearance.content_blocks) && appearance.content_blocks.length) {
        return normalizeContentBlocksForEditor(appearance.content_blocks);
    }

    const blocks = [];
    for (const url of (appearance.banners ?? []).filter(Boolean)) {
        const block = newImageBlock('hero', 'main');
        block.url = String(url).trim();
        blocks.push(block);
    }
    for (const url of (appearance.side_banners ?? []).filter(Boolean)) {
        const block = newImageBlock('portrait', 'sidebar');
        block.url = String(url).trim();
        blocks.push(block);
    }

    return blocks;
}

/**
 * Soft validation: returns warning message or null.
 * @param {number} width
 * @param {number} height
 * @param {string} formatId
 */
export function softValidateImageRatio(width, height, formatId) {
    if (!width || !height) {
        return null;
    }
    const actual = width / height;
    const expected = getFormatRatio(formatId);
    const tolerance = 0.25;
    if (Math.abs(actual - expected) / expected > tolerance) {
        const fmt = getImageFormat(formatId);
        return `Proporção diferente do ideal (${fmt.ratioLabel}). A imagem será cortada no checkout.`;
    }
    return null;
}
