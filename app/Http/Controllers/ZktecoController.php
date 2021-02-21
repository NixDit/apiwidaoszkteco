<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
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

    public function updateDataAttendance($id){
        $error  = false;
        $msg    = null;
        $status = 404;
        $info   = [];
        try {
            $actualAttendance = Attendance::FindAttendance($id);
            if(count($actualAttendance) > 0){
                foreach ($actualAttendance as $value) {
                    Attendance::find($value->id)->delete();
                }
            }
            $data = collect($this->data->getAttendance())->where('id',$id);
            foreach ($data as $value) {
                $info['uid']       = $value['uid'];
                $info['id_code']   = $value['id'];
                $info['state']     = $value['state'];
                $info['timestamp'] = $value['timestamp'];
                $info['type']      = $value['type'];
                Attendance::CreateAttendance($info);
            }
            $status = 201;
        } catch (\Throwable $th) {
            $error  = true;
            $msg    = 'Ocurrió un error al actualizar entradas/salidas:'.$th->getMessage();
            $status = 500;
        }
        return response()->json(array(
            'error'  => $error,
            'msg'    => $msg,
            'status' => $status
        ));
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
