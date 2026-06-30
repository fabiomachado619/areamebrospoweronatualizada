import { ref, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const REDIRECT_DELAY_MS = 2000;

export function useMemberLessonNavigation(getLessonContext, toastRef) {
    const navigating = ref(false);
    let redirectTimer = null;

    function clearRedirectTimer() {
        if (redirectTimer) {
            clearTimeout(redirectTimer);
            redirectTimer = null;
        }
    }

    function showToast(text) {
        toastRef.value?.show?.(text);
    }

    function navigateToUrl(url) {
        if (!url) return;
        router.visit(url);
    }

    function handleNavigationResult(navigation, { courseEndMessage, redirectMessage, delayed = false }) {
        if (!navigation) return;

        if (navigation.is_course_end || !navigation.has_next) {
            showToast(courseEndMessage);
            return;
        }

        if (!navigation.redirect_url) {
            showToast(courseEndMessage);
            return;
        }

        if (delayed) {
            showToast(redirectMessage);
            clearRedirectTimer();
            redirectTimer = setTimeout(() => {
                navigateToUrl(navigation.redirect_url);
            }, REDIRECT_DELAY_MS);
            return;
        }

        navigateToUrl(navigation.redirect_url);
    }

    async function fetchNextNavigation() {
        const ctx = getLessonContext();
        if (!ctx?.lessonId || !ctx?.slug) {
            return null;
        }

        const prefix = ctx.baseUrl?.replace(/\/$/, '') || `/m/${ctx.slug}`;
        const { data } = await axios.get(`${prefix}/aula/${ctx.lessonId}/next`, {
            headers: { Accept: 'application/json' },
        });

        return data?.navigation ?? null;
    }

    async function goToNextLesson() {
        const ctx = getLessonContext();
        if (!ctx?.lessonId || navigating.value) return;

        navigating.value = true;
        try {
            const navigation = await fetchNextNavigation();
            handleNavigationResult(navigation, {
                courseEndMessage: 'Você chegou ao final do curso.',
                redirectMessage: '',
                delayed: false,
            });
        } catch {
            showToast('Não foi possível carregar a próxima aula.');
        } finally {
            navigating.value = false;
        }
    }

    function handleCompletionNavigation(navigation) {
        handleNavigationResult(navigation, {
            courseEndMessage: 'Parabéns, você concluiu o curso!',
            redirectMessage: 'Aula concluída! Redirecionando para a próxima aula...',
            delayed: true,
        });
    }

    onUnmounted(clearRedirectTimer);

    return {
        navigating,
        goToNextLesson,
        handleCompletionNavigation,
        clearRedirectTimer,
    };
}
