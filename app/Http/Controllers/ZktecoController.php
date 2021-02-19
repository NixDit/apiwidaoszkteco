<?php

namespace App\Http\Controllers;

use Rats\Zkteco\Lib\ZKTeco;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ZktecoController extends Controller
{
    public $data;
    public $status = [];

    // type of attendance:
    // 255 = entrada
    // 20  = salida comida
    // 30  = regreso comida
    // 40  = salida
    // 50  = entrada horas extra
    // 60  = salida horas extra

    public function __construct() {
        $ip   = config('app.zkteco_ip');
        $port = (int)config('app.zkteco_port');;
        $this->data = new ZKTeco($ip,$port);
        $this->setConection();
    }
    public function setConection(){
        try {
            $this->data->connect();
            if($this->data->_data_recv != ''){
                if($this->data->connect()){
                    $this->status = array(
                        'error'   => false,
                        'status'  => 'success',
                        'message' => 'Conexión con el checador exitosa'
                    );
                } else {
                    $this->status = array(
                        'error'   => true,
                        'status'  => 'error',
                        'message' => 'Conexión con el checador fallida'
                    );
                }
                
            } else {
                $this->status = array(
                    'error'   => true,
                    'status'  => 'error',
                    'message' => 'Conexión con el checador fallida'
                );
            }
        } catch (\Throwable $th) {
            $this->status = array(
                'error'   => true,
                'status'  => 'error',
                'message' => 'Conexión con el checador fallida'
            );
        }
    }

    public function updateDataAttendance(){
        // dd($this->data->getAttendance());
        // dd(DB::table('employees')->get());
        $error  = false;
        $msg    = null;
        $status = 404;
        // try {
        //     //code...
        // } catch (\Throwable $th) {
        //     $error  = true;
        //     $msg    = 'Ocurrió un error al actualizar entradas/salidas:'.$th->getMessage();
        //     $status = 500;
        // }
        return response()->json($this->data->getAttendance());
    }

    public function saveUser(Request $request){
        $error = false;
        $msg   = null;
        $status = 404;
        try {
            $employee = $request;
            $this->data->setUser(
                $employee->code,
                $employee->code,
                $employee->name
                ,''
            );
            $status = 201;
        } catch (\Throwable $th) {
            $error  = true;
            $msg    = 'Ocurrió un error al guardar usuario ZKTeco:'.$th->getMessage();
            $status = 500;
        }
        return response()->json(array(
            'error'  => $error,
            'msg'    => $msg,
            'status' => $status
        ));
    }

    public function deleteUser(Request $request){
        $error = false;
        $msg   = null;
        $status = 404;
        try {
            $employee = $request;
            $this->data->removeUser($employee->code);
            $status = 201;
        } catch (\Throwable $th) {
            $error  = true;
            $msg    = 'Ocurrió un error al guardar usuario ZKTeco:'.$th->getMessage();
            $status = 500;
        }
        return response()->json(array(
            'error'  => $error,
            'msg'    => $msg,
            'status' => $status
        ));
    }
}
