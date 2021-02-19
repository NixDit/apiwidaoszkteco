<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $table    = 'attendance';
    protected $fillable = [
        'uid',
        'id_code',
        'state',
        'timestamp',
        'type'
    ];

    public function scopeFindAttendance($query,$id){
        return $this->where('id_code',$id)->get();
    }

    public function scopeCreateAttendance($query,$data){
        return $this->create($data);
    }
}
