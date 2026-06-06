<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public const ROLE_ADMIN = 'admin';
    public const ROLE_INFOPRODUTOR = 'infoprodutor';
    public const ROLE_ALUNO = 'aluno';
    public const ROLE_TEAM = 'team';

    public const ROLE_COPRODUTOR = 'coprodutor';

    public const ROLE_AFILIADO = 'afiliado';

    protected $fillable = [
        'name',
        'email',
        'username',
        'avatar',
        'password',
        'role',
        'tenant_id',
        'team_role_id',
        'pix_key',
        'pix_key_type',
        'pix_owner_document',
        'phone',
        'document',
    ];

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isInfoprodutor(): bool
    {
        return $this->role === self::ROLE_INFOPRODUTOR;
    }

    public function isAluno(): bool
    {
        return $this->role === self::ROLE_ALUNO;
    }

    public function isTeam(): bool
    {
        return $this->role === self::ROLE_TEAM;
    }

    public function isCoprodutor(): bool
    {
        return $this->role === self::ROLE_COPRODUTOR;
    }

    public function isAfiliado(): bool
    {
        return $this->role === self::ROLE_AFILIADO;
    }

    public function isPartner(): bool
    {
        return $this->isCoprodutor() || $this->isAfiliado();
    }

    public function usesPartnerPanel(): bool
    {
        return app(\App\Services\PartnerAccessService::class)->usesPartnerPanel($this);
    }

    public function canAccessPanel(): bool
    {
        return $this->isAdmin() || $this->isInfoprodutor() || $this->isTeam() || $this->isPartner();
    }

    public function coproducerProducts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductCoproducer::class);
    }

    public function affiliateProducts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductAffiliate::class);
    }

    public function commissionEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CommissionEntry::class, 'beneficiary_user_id');
    }

    public function walletTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function teamRole(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TeamRole::class, 'team_role_id');
    }

    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_user')->withTimestamps();
    }

    public function subscriptions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function savedPaymentMethods(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SavedPaymentMethod::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
