<?php

set_time_limit(1200);

function lpf($user, $pasw, $name) {
    include "conexion.php";

    $consulta = "SELECT hash FROM masgps.hash WHERE user='$user' AND pasw='$pasw'";
    $resultado = mysqli_query($mysqli, $consulta);

    if (!$resultado) {
        die("Error en la consulta del hash: " . mysqli_error($mysqli));
    }

    $data = mysqli_fetch_array($resultado);
    $hash = $data['hash'];

    date_default_timezone_set("America/Santiago");
    $hoy = date("Y-m-d");

    // Configuración para obtener la lista de trackers
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'http://www.trackermasgps.com/api-v2/tracker/list',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => '{"hash":"' . $hash . '"}',
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json'
        ),
    ));

    $response2 = curl_exec($curl);
    curl_close($curl);

    $json = json_decode($response2);
    $array = $json->list;

    if (!$array) {
        die("Error en la respuesta de la API: " . print_r($json, true));
    }

    // Mapa para almacenar datos de los trackers
    $trackers = [];

    foreach ($array as $item) {
        $id = $item->id;
        $trackers[$id] = [
            'imei' => $item->source->device_id,
            'group' => $item->group_id,
            'label' => $item->label
        ];
    }

    // Preparación para el uso de curl_multi en lotes de 10
    $tracker_ids = array_keys($trackers);
    $total_trackers = count($tracker_ids);
    $batch_size = 10;

    for ($i = 0; $i < $total_trackers; $i += $batch_size) {
        $mh = curl_multi_init();
        $curl_array = [];

        // Procesar un lote de hasta 10 trackers
        $batch_ids = array_slice($tracker_ids, $i, $batch_size);

        foreach ($batch_ids as $id) {
            // Configuración de la solicitud para cada tracker
            $curl_array[$id] = curl_init();
            curl_setopt_array($curl_array[$id], array(
                CURLOPT_URL => 'http://www.trackermasgps.com/api-v2/tracker/get_state',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => '{"hash": "' . $hash . '", "tracker_id": ' . $id . '}',
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json'
                ),
            ));
            curl_multi_add_handle($mh, $curl_array[$id]);
        }

        // Ejecutar todas las solicitudes en el lote
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        // Procesar cada respuesta en el lote
        foreach ($curl_array as $id => $ch) {
            $response2 = curl_multi_getcontent($ch);
            $json2 = json_decode($response2);

            if ($json2 && isset($json2->state)) {
                $lat = $json2->state->gps->location->lat ?? 'NULL';
                $lng = $json2->state->gps->location->lng ?? 'NULL';
                $last_u = $json2->state->last_update ?? 'NULL';
                $plate = $trackers[$id]['label'] ?? 'NULL';
                $status = $json2->state->connection_status ?? 'NULL';
                $imei = $trackers[$id]['imei'] ?? 'NULL';
                $group = $trackers[$id]['group'] ?? 'NULL';

                $sql = "INSERT INTO lpfExternos2 (cuenta, id_tracker, `lat`, `lng`, `patente`, `fecha`, `last_update`, `imei`, `connection_status`, `grupo`) 
                        VALUES ('$name', '$id', '$lat', '$lng', '$plate', '$hoy', '$last_u', '$imei', '$status', '$group')";

                $datosduplicados = mysqli_query($mysqli, "SELECT * FROM lpfExternos2 WHERE id_tracker='$id'");

                if (mysqli_num_rows($datosduplicados) > 0) {
                    $sql1 = "UPDATE lpfExternos2 SET `lat`='$lat', `lng`='$lng', `last_update`='$last_u', `fecha`='$hoy', `cuenta`='$name', `imei`='$imei', `connection_status`='$status', `grupo`='$group' 
                             WHERE `id_tracker`='$id'";
                    if (!mysqli_query($mysqli, $sql1)) {
                        die("Error en la actualización de id_tracker = $id: " . mysqli_error($mysqli));
                    }
                    echo "Actualizado: id_tracker = $id <br>";
                } else {
                    if (!mysqli_query($mysqli, $sql)) {
                        die("Error en la inserción de id_tracker = $id: " . mysqli_error($mysqli));
                    }
                    echo "Creado: id_tracker = $id <br>";
                }
            } else {
                echo "Error al procesar la respuesta para id_tracker = $id: " . print_r($json2, true) . "<br>";
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
    }
}
?>

