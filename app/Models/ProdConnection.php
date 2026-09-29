<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProdConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's read-only production database connection (SB-17). Only
 * ProductionReader opens it. Host, database, username and password are
 * encrypted at rest with APP_KEY; the password is hidden from arrays and JSON
 * and is never rendered back into a form.
 *
 * @property int $id
 * @property int $project_id
 * @property string $host
 * @property int $port
 * @property string $database
 * @property string $username
 * @property string $password
 * @property bool $use_ssl
 * @property CarbonImmutable|null $verified_at
 * @property-read Project $project
 */
class ProdConnection extends Model
{
    /** @use HasFactory<ProdConnectionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['project_id', 'host', 'port', 'database', 'username', 'password', 'use_ssl', 'verified_at'];

    /** @var list<string> */
    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'host' => 'encrypted',
            'port' => 'integer',
            'database' => 'encrypted',
            'username' => 'encrypted',
            'password' => 'encrypted',
            'use_ssl' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The project this production database belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * `host:port`, for messages. Never logged: the host is a credential.
     */
    public function target(): string
    {
        return $this->host.':'.$this->port;
    }
}
