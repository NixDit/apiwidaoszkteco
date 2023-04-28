<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Attendance_2;
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

    public function updateDataAttendance($id){
        $error  = false;
        $msg    = null;
        $status = 404;
        $info   = [];
        try {
            $zkteco = new ZKTeco(config('app.zkteco_ip'),(int)config('app.zkteco_port')); // Configurar de acuerdo a la IP local asignada al checador en la red.
            $zkteco->connect();
            $zkteco->disableDevice();
            $data = collect($zkteco->getAttendance());
            foreach ($data as $value) {
                $info['uid']       = $value['uid'];
                $info['id_code']   = $value['id'];
                $info['state']     = $value['state'];
                $info['timestamp'] = $value['timestamp'];
                $info['type']      = $value['type'];
                Attendance::CreateAttendance($info); // Configurar de acuerdo a la tabla establecida.
            }
            $zkteco->clearAttendance();
            $status = 201;
        } catch (\Throwable $th) {
            $error  = true;
            $msg    = 'Ocurrió un error al actualizar entradas/salidas:'.$th->getMessage();
            $status = 500;
        }
        $zkteco->enableDevice();
        $zkteco->disconnect();
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
            $zkteco = new ZKTeco(config('app.zkteco_ip'),(int)config('app.zkteco_port'));
            $zkteco->connect();
            $zkteco->disableDevice();
            $employee = $request;
            $zkteco->setUser(
                $employee->code,
                $employee->code,
                $employee->name,
                ''
            );
            $status = 201;
        } catch (\Throwable $th) {
            $error  = true;
            $msg    = 'Ocurrió un error al guardar usuario ZKTeco:'.$th->getMessage();
            $status = 500;
        }
        $zkteco->enableDevice();
        $zkteco->disconnect();
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
            $zkteco = new ZKTeco(config('app.zkteco_ip'),(int)config('app.zkteco_port'));
            $zkteco->connect();
            $zkteco->disableDevice();
            $employee = $request;
            $zkteco->removeUser($employee->code);
            $status = 201;
        } catch (\Throwable $th) {
            $error  = true;
            $msg    = 'Ocurrió un error al eliminar usuario ZKTeco:'.$th->getMessage();
            $status = 500;
        }
        $zkteco->enableDevice();
        $zkteco->disconnect();
        return response()->json(array(
            'error'  => $error,
            'msg'    => $msg,
            'status' => $status
        ));
    }

    public function updateDataAttendance2($id){
        $error  = false;
        $msg    = null;
        $status = 404;
        $info   = [];
        try {
            $zkteco = new ZKTeco(config('app.zkteco_ip_2'),(int)config('app.zkteco_port')); // Configurar de acuerdo a la IP local asignada al checador en la red.
            $zkteco->connect();
            $zkteco->disableDevice();
            $data = collect($zkteco->getAttendance());
            foreach ($data as $value) {
                $info['uid']       = $value['uid'];
                $info['id_code']   = $value['id'];
                $info['state']     = $value['state'];
                $info['timestamp'] = $value['timestamp'];
                $info['type']      = $value['type'];
                Attendance_2::CreateAttendance($info); // Configurar de acuerdo a la tabla establecida.
            }
            $zkteco->clearAttendance();
            $status = 201;
        } catch (\Throwable $th) {
            $error  = true;
            $msg    = 'Ocurrió un error al actualizar entradas/salidas:'.$th->getMessage();
            $status = 500;
        }
        $zkteco->enableDevice();
        $zkteco->disconnect();
        return response()->json(array(
            'error'  => $error,
            'msg'    => $msg,
            'status' => $status
        ));
    }

    public function saveUser2(Request $request){
        $error = false;
        $msg   = null;
        $status = 404;
        try {
            $zkteco = new ZKTeco(config('app.zkteco_ip_2'),(int)config('app.zkteco_port'));
            $zkteco->connect();
            $zkteco->disableDevice();
            $employee = $request;
            $zkteco->setUser(
                $employee->code,
                $employee->code,
                $employee->name,
                ''
            );
            $status = 201;
        } catch (\Throwable $th) {
            $error  = true;
            $msg    = 'Ocurrió un error al guardar usuario ZKTeco:'.$th->getMessage();
            $status = 500;
        }
        $zkteco->enableDevice();
        $zkteco->disconnect();
        return response()->json(array(
            'error'  => $error,
            'msg'    => $msg,
            'status' => $status
        ));
    }

    public function deleteUser2(Request $request){
        $error = false;
        $msg   = null;
        $status = 404;
        try {
            $zkteco = new ZKTeco(config('app.zkteco_ip_2'),(int)config('app.zkteco_port'));
            $zkteco->connect();
            $zkteco->disableDevice();
            $employee = $request;
            $zkteco->removeUser($employee->code);
            $status = 201;
        } catch (\Throwable $th) {
            $error  = true;
            $msg    = 'Ocurrió un error al eliminar usuario ZKTeco:'.$th->getMessage();
            $status = 500;
        }
        $zkteco->enableDevice();
        $zkteco->disconnect();
        return response()->json(array(
            'error'  => $error,
            'msg'    => $msg,
            'status' => $status
        ));
    }
}
