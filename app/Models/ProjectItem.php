<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectItem extends Model
{
        protected $fillable = ['project_id', 'material_id', 'jumlah_diminta', 'jumlah_keluar'];

    public function project() 
    {
        return $this->belongsTo(Project::class);
    }

    public function material() {
        return $this->belongsTo(Material::class);
    }
}
