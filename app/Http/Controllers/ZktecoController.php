<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Attendance_2;
use Rats\Zkteco\Lib\ZKTeco;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    public function updateDataAttendance($id)
    {
        $error = false;
        $msg = null;
        $status = 200;

        $zkteco = null;
        $connected = false;
        $disabled = false;

        $totalDevice = 0;
        $totalSaved = 0;

        set_time_limit(900);

        try {

            $zkteco = new ZKTeco(
                config('app.zkteco_ip'),
                (int) config('app.zkteco_port')
            );

            Log::info('Intentando conexión ZKTeco para actualizar asistencias', [
                'ip' => config('app.zkteco_ip'),
                'port' => config('app.zkteco_port'),
                'id' => $id,
            ]);

            /*
            * 1. Conectar
            */
            $connected = $zkteco->connect();

            Log::info('Resultado conexión ZKTeco', [
                'connected' => $connected,
            ]);

            if (!$connected) {
                throw new \RuntimeException(
                    'No fue posible establecer conexión con el dispositivo ZKTeco.'
                );
            }

            /*
            * 2. Deshabilitar temporalmente el dispositivo
            */
            $disableResult = $zkteco->disableDevice();

            if ($disableResult === false) {
                throw new \RuntimeException(
                    'No fue posible deshabilitar temporalmente el dispositivo ZKTeco.'
                );
            }

            $disabled = true;

            /*
            * 3. Obtener asistencias
            */
            Log::info('Consultando registros de asistencia ZKTeco');

            $attendance = $zkteco->getAttendance();

            if ($attendance === false) {
                throw new \RuntimeException(
                    'El dispositivo ZKTeco no pudo devolver los registros de asistencia.'
                );
            }

            $data = collect($attendance);

            $totalDevice = $data->count();

            Log::info('Registros obtenidos del ZKTeco', [
                'count' => $totalDevice,
            ]);

            /*
            * 4. Si no hay registros, terminamos sin intentar borrar nada
            */
            if ($data->isEmpty()) {

                $msg = 'No existen nuevos registros de asistencia para procesar.';

                Log::info($msg);

                return response()->json([
                    'error' => false,
                    'msg' => $msg,
                    'status' => 200,
                    'records_received' => 0,
                    'records_saved' => 0,
                ], 200);
            }

            /*
            * 5. Guardar todo en una transacción.
            *
            * Si falla un registro, se revierte todo y NO se limpian
            * las asistencias del checador.
            */
            DB::transaction(function () use ($data, &$totalSaved) {

                foreach ($data as $value) {

                    /*
                    * Validamos que el registro tenga la estructura esperada.
                    */
                    if (
                        !isset(
                            $value['uid'],
                            $value['id'],
                            $value['state'],
                            $value['timestamp'],
                            $value['type']
                        )
                    ) {
                        throw new \UnexpectedValueException(
                            'Se recibió un registro de asistencia incompleto desde el ZKTeco.'
                        );
                    }

                    $info = [
                        'uid' => $value['uid'],
                        'id_code' => $value['id'],
                        'state' => $value['state'],
                        'timestamp' => $value['timestamp'],
                        'type' => $value['type'],
                    ];

                    $created = Attendance::CreateAttendance($info);

                    /*
                    * Si tu método devuelve false cuando no pudo guardar,
                    * detenemos todo el proceso.
                    */
                    if ($created === false) {
                        throw new \RuntimeException(
                            'No fue posible guardar uno de los registros de asistencia.'
                        );
                    }

                    $totalSaved++;
                }
            });

            /*
            * 6. Limpiar el ZKTeco solamente después de que TODOS
            * los registros quedaron guardados correctamente.
            */
            Log::info('Registros guardados. Limpiando asistencias del ZKTeco', [
                'saved' => $totalSaved,
            ]);

            $clearResult = $zkteco->clearAttendance();

            if ($clearResult === false) {
                /*
                * Ojo:
                * los registros YA están guardados en nuestra BD.
                * No lanzamos una excepción destructiva sin dejar claro
                * que el problema fue solamente la limpieza del dispositivo.
                */
                Log::warning(
                    'Las asistencias fueron guardadas pero no fue posible limpiar el ZKTeco',
                    [
                        'records_saved' => $totalSaved,
                    ]
                );

                $msg = 'Las asistencias fueron guardadas, pero no fue posible limpiar los registros del checador.';

                return response()->json([
                    'error' => true,
                    'msg' => $msg,
                    'status' => 207,
                    'records_received' => $totalDevice,
                    'records_saved' => $totalSaved,
                ], 207);
            }

            $msg = 'Asistencias actualizadas correctamente.';

            Log::info('Actualización de asistencias ZKTeco exitosa', [
                'received' => $totalDevice,
                'saved' => $totalSaved,
            ]);

        } catch (\Throwable $th) {

            $error = true;

            /*
            * Si ni siquiera se pudo conectar, es más correcto
            * responder 503 que 500.
            */
            $status = $connected ? 500 : 503;

            $msg = 'Ocurrió un error al actualizar entradas/salidas: '
                . $th->getMessage();

            Log::error('Error actualizando asistencias ZKTeco', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'connected' => $connected,
                'records_received' => $totalDevice,
                'records_saved' => $totalSaved,
            ]);

        } finally {

            /*
            * Solo intentar habilitar el dispositivo si realmente
            * logramos conectarnos y deshabilitarlo.
            */
            if ($zkteco && $connected && $disabled) {

                try {

                    Log::info('Habilitando nuevamente dispositivo ZKTeco');

                    $zkteco->enableDevice();

                } catch (\Throwable $th) {

                    Log::warning(
                        'No fue posible habilitar nuevamente el ZKTeco',
                        [
                            'message' => $th->getMessage(),
                        ]
                    );
                }
            }

            /*
            * Desconectar únicamente si hubo conexión.
            */
            if ($zkteco && $connected) {

                try {

                    $zkteco->disconnect();

                    Log::info('ZKTeco desconectado correctamente');

                } catch (\Throwable $th) {

                    Log::warning(
                        'Error al desconectar ZKTeco',
                        [
                            'message' => $th->getMessage(),
                        ]
                    );
                }
            }
        }

        return response()->json([
            'error' => $error,
            'msg' => $msg,
            'status' => $status,
            'records_received' => $totalDevice,
            'records_saved' => $totalSaved,
        ], $status);
    }

    public function saveUser(Request $request)
    {
        $error = false;
        $msg = null;
        $status = 404;

        set_time_limit(900);

        $zkteco = new ZKTeco(
            config('app.zkteco_ip'),
            (int) config('app.zkteco_port')
        );

        $connected = false;
        $disabled = false;

        try {

            Log::info('Intentando conexión ZKTeco', [
                'ip' => config('app.zkteco_ip'),
                'port' => config('app.zkteco_port'),
            ]);

            /*
            * 1. Conectar
            */
            $connected = $zkteco->connect();

            Log::info('Resultado conexión ZKTeco', [
                'connected' => $connected
            ]);

            if (!$connected) {
                throw new \RuntimeException(
                    'No fue posible establecer conexión con el dispositivo ZKTeco.'
                );
            }

            /*
            * 2. Deshabilitar temporalmente
            */
            Log::info('Deshabilitando ZKTeco');

            $zkteco->disableDevice();

            $disabled = true;

            /*
            * 3. Guardar usuario
            */
            Log::info('Guardando usuario en ZKTeco', [
                'code' => $request->code,
                'name' => $request->name,
            ]);

            $zkteco->setUser(
                $request->code,
                $request->code,
                $request->name,
                ''
            );

            $status = 201;
            $msg = 'Usuario guardado correctamente en ZKTeco';

            Log::info('Conexión de tipo save user exitosa');

        } catch (\Throwable $th) {

            $error = true;

            $msg = 'Ocurrió un error al guardar usuario ZKTeco: '
                . $th->getMessage();

            $status = 500;

            Log::error('Error en saveUser ZKTeco', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

        } finally {

            /*
            * Intentar rehabilitar solamente si logramos
            * conectarnos y anteriormente lo deshabilitamos.
            */
            if ($connected && $disabled) {

                try {

                    Log::info('Habilitando nuevamente ZKTeco');

                    $zkteco->enableDevice();

                } catch (\Throwable $th) {

                    Log::warning('No fue posible habilitar nuevamente ZKTeco', [
                        'message' => $th->getMessage(),
                    ]);
                }
            }

            /*
            * Desconectar solamente si hubo conexión.
            */
            if ($connected) {

                try {

                    $zkteco->disconnect();

                    Log::info('ZKTeco desconectado correctamente');

                } catch (\Throwable $th) {

                    Log::warning('Error desconectando ZKTeco', [
                        'message' => $th->getMessage(),
                    ]);
                }
            }
        }

        return response()->json([
            'error' => $error,
            'msg' => $msg,
            'status' => $status,
        ], $status);
    }

    public function deleteUser(Request $request)
    {
        $error = false;
        $msg = null;
        $status = 200;

        $zkteco = null;
        $connected = false;
        $disabled = false;

        try {

            /*
            * Validar datos necesarios
            */
            $request->validate([
                'code' => 'required',
            ]);

            set_time_limit(900);

            $zkteco = new ZKTeco(
                config('app.zkteco_ip'),
                (int) config('app.zkteco_port')
            );

            Log::info('Intentando conexión ZKTeco para eliminar usuario', [
                'ip' => config('app.zkteco_ip'),
                'port' => config('app.zkteco_port'),
                'code' => $request->code,
            ]);

            /*
            * 1. Conectar
            */
            $connected = $zkteco->connect();

            Log::info('Resultado conexión ZKTeco', [
                'connected' => $connected,
            ]);

            if (!$connected) {
                throw new \RuntimeException(
                    'No fue posible establecer conexión con el dispositivo ZKTeco.'
                );
            }

            /*
            * 2. Deshabilitar temporalmente el dispositivo
            */
            Log::info('Deshabilitando temporalmente ZKTeco');

            $disableResult = $zkteco->disableDevice();

            if ($disableResult === false) {
                throw new \RuntimeException(
                    'No fue posible deshabilitar temporalmente el dispositivo ZKTeco.'
                );
            }

            $disabled = true;

            /*
            * 3. Eliminar usuario
            */
            Log::info('Eliminando usuario del ZKTeco', [
                'code' => $request->code,
            ]);

            $result = $zkteco->removeUser($request->code);

            /*
            * Solo evaluamos false de forma estricta.
            * Algunas versiones de la librería pueden devolver null
            * aunque el comando se haya ejecutado.
            */
            if ($result === false) {
                throw new \RuntimeException(
                    'El dispositivo ZKTeco no pudo eliminar el usuario.'
                );
            }

            $msg = 'Usuario eliminado correctamente del dispositivo ZKTeco.';
            $status = 200;

            Log::info('Usuario eliminado correctamente del ZKTeco', [
                'code' => $request->code,
            ]);

        } catch (\Illuminate\Validation\ValidationException $th) {

            /*
            * Dejamos que Laravel conserve correctamente el error 422
            * de validación.
            */
            throw $th;

        } catch (\Throwable $th) {

            $error = true;

            /*
            * Si nunca hubo conexión, el dispositivo está temporalmente
            * no disponible.
            */
            $status = $connected ? 500 : 503;

            $msg = 'Ocurrió un error al eliminar usuario ZKTeco: '
                . $th->getMessage();

            Log::error('Error eliminando usuario ZKTeco', [
                'code' => $request->code ?? null,
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'connected' => $connected,
            ]);

        } finally {

            /*
            * Solamente intentamos habilitarlo si realmente
            * logramos conectarlo y deshabilitarlo.
            */
            if ($zkteco && $connected && $disabled) {

                try {

                    Log::info('Habilitando nuevamente ZKTeco');

                    $zkteco->enableDevice();

                } catch (\Throwable $th) {

                    Log::warning(
                        'No fue posible habilitar nuevamente el ZKTeco',
                        [
                            'message' => $th->getMessage(),
                        ]
                    );
                }
            }

            /*
            * Desconectar solamente si hubo conexión.
            */
            if ($zkteco && $connected) {

                try {

                    $zkteco->disconnect();

                    Log::info('ZKTeco desconectado correctamente');

                } catch (\Throwable $th) {

                    Log::warning(
                        'Error al desconectar ZKTeco',
                        [
                            'message' => $th->getMessage(),
                        ]
                    );
                }
            }
        }

        return response()->json([
            'error' => $error,
            'msg' => $msg,
            'status' => $status,
        ], $status);
    }

    public function updateDataAttendance2($id)
    {
        $error = false;
        $msg = null;
        $status = 200;

        $zkteco = null;
        $connected = false;
        $disabled = false;

        $totalDevice = 0;
        $totalSaved = 0;

        set_time_limit(900);

        try {

            $zkteco = new ZKTeco(
                config('app.zkteco_ip_2'),
                (int) config('app.zkteco_port')
            );

            Log::info('Intentando conexión ZKTeco (checador 2) para actualizar asistencias', [
                'ip' => config('app.zkteco_ip_2'),
                'port' => config('app.zkteco_port'),
                'id' => $id,
            ]);

            /*
            * 1. Conectar
            */
            $connected = $zkteco->connect();

            Log::info('Resultado conexión ZKTeco (checador 2)', [
                'connected' => $connected,
            ]);

            if (!$connected) {
                throw new \RuntimeException(
                    'No fue posible establecer conexión con el dispositivo ZKTeco.'
                );
            }

            /*
            * 2. Deshabilitar temporalmente el dispositivo
            */
            $disableResult = $zkteco->disableDevice();

            if ($disableResult === false) {
                throw new \RuntimeException(
                    'No fue posible deshabilitar temporalmente el dispositivo ZKTeco.'
                );
            }

            $disabled = true;

            /*
            * 3. Obtener asistencias
            */
            Log::info('Consultando registros de asistencia ZKTeco (checador 2)');

            $attendance = $zkteco->getAttendance();

            if ($attendance === false) {
                throw new \RuntimeException(
                    'El dispositivo ZKTeco no pudo devolver los registros de asistencia.'
                );
            }

            $data = collect($attendance);

            $totalDevice = $data->count();

            Log::info('Registros obtenidos del ZKTeco (checador 2)', [
                'count' => $totalDevice,
            ]);

            /*
            * 4. Si no hay registros, terminamos sin intentar borrar nada
            */
            if ($data->isEmpty()) {

                $msg = 'No existen nuevos registros de asistencia para procesar.';

                Log::info($msg);

                return response()->json([
                    'error' => false,
                    'msg' => $msg,
                    'status' => 200,
                    'records_received' => 0,
                    'records_saved' => 0,
                ], 200);
            }

            /*
            * 5. Guardar todo en una transacción.
            *
            * Si falla un registro, se revierte todo y NO se limpian
            * las asistencias del checador.
            */
            DB::transaction(function () use ($data, &$totalSaved) {

                foreach ($data as $value) {

                    /*
                    * Validamos que el registro tenga la estructura esperada.
                    */
                    if (
                        !isset(
                            $value['uid'],
                            $value['id'],
                            $value['state'],
                            $value['timestamp'],
                            $value['type']
                        )
                    ) {
                        throw new \UnexpectedValueException(
                            'Se recibió un registro de asistencia incompleto desde el ZKTeco.'
                        );
                    }

                    $info = [
                        'uid' => $value['uid'],
                        'id_code' => $value['id'],
                        'state' => $value['state'],
                        'timestamp' => $value['timestamp'],
                        'type' => $value['type'],
                    ];

                    $created = Attendance_2::CreateAttendance($info);

                    /*
                    * Si tu método devuelve false cuando no pudo guardar,
                    * detenemos todo el proceso.
                    */
                    if ($created === false) {
                        throw new \RuntimeException(
                            'No fue posible guardar uno de los registros de asistencia.'
                        );
                    }

                    $totalSaved++;
                }
            });

            /*
            * 6. Limpiar el ZKTeco solamente después de que TODOS
            * los registros quedaron guardados correctamente.
            */
            Log::info('Registros guardados. Limpiando asistencias del ZKTeco (checador 2)', [
                'saved' => $totalSaved,
            ]);

            $clearResult = $zkteco->clearAttendance();

            if ($clearResult === false) {
                /*
                * Ojo:
                * los registros YA están guardados en nuestra BD.
                * No lanzamos una excepción destructiva sin dejar claro
                * que el problema fue solamente la limpieza del dispositivo.
                */
                Log::warning(
                    'Las asistencias fueron guardadas pero no fue posible limpiar el ZKTeco (checador 2)',
                    [
                        'records_saved' => $totalSaved,
                    ]
                );

                $msg = 'Las asistencias fueron guardadas, pero no fue posible limpiar los registros del checador.';

                return response()->json([
                    'error' => true,
                    'msg' => $msg,
                    'status' => 207,
                    'records_received' => $totalDevice,
                    'records_saved' => $totalSaved,
                ], 207);
            }

            $msg = 'Asistencias actualizadas correctamente.';

            Log::info('Actualización de asistencias ZKTeco exitosa (checador 2)', [
                'received' => $totalDevice,
                'saved' => $totalSaved,
            ]);

        } catch (\Throwable $th) {

            $error = true;

            /*
            * Si ni siquiera se pudo conectar, es más correcto
            * responder 503 que 500.
            */
            $status = $connected ? 500 : 503;

            $msg = 'Ocurrió un error al actualizar entradas/salidas: '
                . $th->getMessage();

            Log::error('Error actualizando asistencias ZKTeco (checador 2)', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'connected' => $connected,
                'records_received' => $totalDevice,
                'records_saved' => $totalSaved,
            ]);

        } finally {

            /*
            * Solo intentar habilitar el dispositivo si realmente
            * logramos conectarnos y deshabilitarlo.
            */
            if ($zkteco && $connected && $disabled) {

                try {

                    Log::info('Habilitando nuevamente dispositivo ZKTeco (checador 2)');

                    $zkteco->enableDevice();

                } catch (\Throwable $th) {

                    Log::warning(
                        'No fue posible habilitar nuevamente el ZKTeco (checador 2)',
                        [
                            'message' => $th->getMessage(),
                        ]
                    );
                }
            }

            /*
            * Desconectar únicamente si hubo conexión.
            */
            if ($zkteco && $connected) {

                try {

                    $zkteco->disconnect();

                    Log::info('ZKTeco desconectado correctamente (checador 2)');

                } catch (\Throwable $th) {

                    Log::warning(
                        'Error al desconectar ZKTeco (checador 2)',
                        [
                            'message' => $th->getMessage(),
                        ]
                    );
                }
            }
        }

        return response()->json([
            'error' => $error,
            'msg' => $msg,
            'status' => $status,
            'records_received' => $totalDevice,
            'records_saved' => $totalSaved,
        ], $status);
    }

    public function saveUser2(Request $request)
    {
        $error = false;
        $msg = null;
        $status = 404;

        set_time_limit(900);

        $zkteco = new ZKTeco(
            config('app.zkteco_ip_2'),
            (int) config('app.zkteco_port')
        );

        $connected = false;
        $disabled = false;

        try {

            Log::info('Intentando conexión ZKTeco (checador 2)', [
                'ip' => config('app.zkteco_ip_2'),
                'port' => config('app.zkteco_port'),
            ]);

            /*
            * 1. Conectar
            */
            $connected = $zkteco->connect();

            Log::info('Resultado conexión ZKTeco (checador 2)', [
                'connected' => $connected
            ]);

            if (!$connected) {
                throw new \RuntimeException(
                    'No fue posible establecer conexión con el dispositivo ZKTeco.'
                );
            }

            /*
            * 2. Deshabilitar temporalmente
            */
            Log::info('Deshabilitando ZKTeco (checador 2)');

            $zkteco->disableDevice();

            $disabled = true;

            /*
            * 3. Guardar usuario
            */
            Log::info('Guardando usuario en ZKTeco (checador 2)', [
                'code' => $request->code,
                'name' => $request->name,
            ]);

            $zkteco->setUser(
                $request->code,
                $request->code,
                $request->name,
                ''
            );

            $status = 201;
            $msg = 'Usuario guardado correctamente en ZKTeco';

            Log::info('Conexión de tipo save user exitosa (checador 2)');

        } catch (\Throwable $th) {

            $error = true;

            $msg = 'Ocurrió un error al guardar usuario ZKTeco: '
                . $th->getMessage();

            $status = 500;

            Log::error('Error en saveUser2 ZKTeco', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

        } finally {

            /*
            * Intentar rehabilitar solamente si logramos
            * conectarnos y anteriormente lo deshabilitamos.
            */
            if ($connected && $disabled) {

                try {

                    Log::info('Habilitando nuevamente ZKTeco (checador 2)');

                    $zkteco->enableDevice();

                } catch (\Throwable $th) {

                    Log::warning('No fue posible habilitar nuevamente ZKTeco (checador 2)', [
                        'message' => $th->getMessage(),
                    ]);
                }
            }

            /*
            * Desconectar solamente si hubo conexión.
            */
            if ($connected) {

                try {

                    $zkteco->disconnect();

                    Log::info('ZKTeco desconectado correctamente (checador 2)');

                } catch (\Throwable $th) {

                    Log::warning('Error desconectando ZKTeco (checador 2)', [
                        'message' => $th->getMessage(),
                    ]);
                }
            }
        }

        return response()->json([
            'error' => $error,
            'msg' => $msg,
            'status' => $status,
        ], $status);
    }

    public function deleteUser2(Request $request)
    {
        $error = false;
        $msg = null;
        $status = 200;

        $zkteco = null;
        $connected = false;
        $disabled = false;

        try {

            /*
            * Validar datos necesarios
            */
            $request->validate([
                'code' => 'required',
            ]);

            set_time_limit(900);

            $zkteco = new ZKTeco(
                config('app.zkteco_ip_2'),
                (int) config('app.zkteco_port')
            );

            Log::info('Intentando conexión ZKTeco (checador 2) para eliminar usuario', [
                'ip' => config('app.zkteco_ip_2'),
                'port' => config('app.zkteco_port'),
                'code' => $request->code,
            ]);

            /*
            * 1. Conectar
            */
            $connected = $zkteco->connect();

            Log::info('Resultado conexión ZKTeco (checador 2)', [
                'connected' => $connected,
            ]);

            if (!$connected) {
                throw new \RuntimeException(
                    'No fue posible establecer conexión con el dispositivo ZKTeco.'
                );
            }

            /*
            * 2. Deshabilitar temporalmente el dispositivo
            */
            Log::info('Deshabilitando temporalmente ZKTeco (checador 2)');

            $disableResult = $zkteco->disableDevice();

            if ($disableResult === false) {
                throw new \RuntimeException(
                    'No fue posible deshabilitar temporalmente el dispositivo ZKTeco.'
                );
            }

            $disabled = true;

            /*
            * 3. Eliminar usuario
            */
            Log::info('Eliminando usuario del ZKTeco (checador 2)', [
                'code' => $request->code,
            ]);

            $result = $zkteco->removeUser($request->code);

            /*
            * Solo evaluamos false de forma estricta.
            * Algunas versiones de la librería pueden devolver null
            * aunque el comando se haya ejecutado.
            */
            if ($result === false) {
                throw new \RuntimeException(
                    'El dispositivo ZKTeco no pudo eliminar el usuario.'
                );
            }

            $msg = 'Usuario eliminado correctamente del dispositivo ZKTeco.';
            $status = 200;

            Log::info('Usuario eliminado correctamente del ZKTeco (checador 2)', [
                'code' => $request->code,
            ]);

        } catch (\Illuminate\Validation\ValidationException $th) {

            /*
            * Dejamos que Laravel conserve correctamente el error 422
            * de validación.
            */
            throw $th;

        } catch (\Throwable $th) {

            $error = true;

            /*
            * Si nunca hubo conexión, el dispositivo está temporalmente
            * no disponible.
            */
            $status = $connected ? 500 : 503;

            $msg = 'Ocurrió un error al eliminar usuario ZKTeco: '
                . $th->getMessage();

            Log::error('Error eliminando usuario ZKTeco (checador 2)', [
                'code' => $request->code ?? null,
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'connected' => $connected,
            ]);

        } finally {

            /*
            * Solamente intentamos habilitarlo si realmente
            * logramos conectarlo y deshabilitarlo.
            */
            if ($zkteco && $connected && $disabled) {

                try {

                    Log::info('Habilitando nuevamente ZKTeco (checador 2)');

                    $zkteco->enableDevice();

                } catch (\Throwable $th) {

                    Log::warning(
                        'No fue posible habilitar nuevamente el ZKTeco (checador 2)',
                        [
                            'message' => $th->getMessage(),
                        ]
                    );
                }
            }

            /*
            * Desconectar solamente si hubo conexión.
            */
            if ($zkteco && $connected) {

                try {

                    $zkteco->disconnect();

                    Log::info('ZKTeco desconectado correctamente (checador 2)');

                } catch (\Throwable $th) {

                    Log::warning(
                        'Error al desconectar ZKTeco (checador 2)',
                        [
                            'message' => $th->getMessage(),
                        ]
                    );
                }
            }
        }

        return response()->json([
            'error' => $error,
            'msg' => $msg,
            'status' => $status,
        ], $status);
    }
}
