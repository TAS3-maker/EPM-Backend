<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'profile_pic',
        'address',
        'phone_num',
        'emergency_phone_num',
        'tl_id',
        'password',
        'team_id',
        'role_id',
        'employee_id'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'team_id' => 'array',
    ];

    /**
     * JWT Identifier
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * JWT Custom Claims
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /**
     * Get the role associated with the user.
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }   
    /**
     * Get the team associated with the user.
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the projects assigned to the user.
     */


	public function projectManager()
    {
        return $this->hasMany(Project::class, 'project_manager_id');
    }

public function assignedProjects()
{
    return $this->belongsToMany(Project::class, 'project_user')
                ->withPivot('project_manager_id', 'created_at', 'updated_at')
                ->withTimestamps();
}

public function assigns() {
    return $this->hasMany(AccessoryAssign::class);
}

 public function leadProjects()
{
    return $this->hasMany(Project::class, 'tl_id');
}

    public function leaves()
    {
        return $this->hasMany(LeavePolicy::class, 'user_id');
    }
    public function belongsToTeam($teamId)
    {
        return in_array($teamId, $this->team_id ?? []);
    }
    public function getTeamNamesAttribute()
    {
        if (!$this->team_id || !is_array($this->team_id)) return null;
        return Team::whereIn('id', $this->team_id)->pluck('name')->toArray();
    }
    public function tl()
    {
        return $this->belongsTo(User::class, 'tl_id');
    }
}
