<?php

set_time_limit(1200);

include "conexion.php";

$user = "Pullman";
$pasw = "123";
$name = 'Pullman';

$consulta = "SELECT hash FROM masgps.hash WHERE user='$user' AND pasw='$pasw'";
$resultado = mysqli_query($mysqli, $consulta);

if (!$resultado) {
    die("Error en la consulta del hash: " . mysqli_error($mysqli));
}

$data = mysqli_fetch_array($resultado);
$hash = $data['hash'];

date_default_timezone_set("America/Santiago");
$hoy = date("Y-m-d");

// Obtener la lista de trackers
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
        'Accept-Language: es-419,es;q=0.9,en;q=0.8',
        'Connection: keep-alive',
        'Content-Type: application/json',
        'Cookie: _ga=GA1.2.728367267.1665672802; locale=es; _gid=GA1.2.967319985.1673009696; _gat=1; session_key=5d7875e2bf96b5966225688ddea8f098',
        'Origin: http://www.trackermasgps.com',
        'Referer: http://www.trackermasgps.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36'
    ),
));

$response2 = curl_exec($curl);
curl_close($curl);

$json = json_decode($response2);
$array = $json->list;

// Dividir la lista en lotes de 20
$lotes = array_chunk($array, 20);

foreach ($lotes as $lote) {
    // Preparar múltiples solicitudes cURL para obtener el estado de los trackers
    $multiCurl = array();
    $mh = curl_multi_init();

    foreach ($lote as $item) {
        $id = $item->id;
        $imei = $item->source->device_id;
        $group = $item->group_id;
        
        $multiCurl[$id] = curl_init();
        
        curl_setopt_array($multiCurl[$id], array(
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
        
        curl_multi_add_handle($mh, $multiCurl[$id]);
    }

    // Ejecutar las solicitudes en paralelo
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    // Procesar las respuestas
    foreach ($multiCurl as $id => $ch) {
        $response2 = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        
        $json2 = json_decode($response2);
        
        $lat = $json2->state->gps->location->lat;
        $lng = $json2->state->gps->location->lng;
        $last_u = $json2->state->last_update;
        $plate = $item->label;
        $status = $json2->state->connection_status;

        // Obtener dirección
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'http://www.trackermasgps.com/api-v2/geocoder/search_location',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '{"location":{"lat":' . $lat . ',"lng":' . $lng . '},"lang":"es","hash":"' . $hash . '"}',
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json, text/plain, */*',
                'Accept-Language: es-419,es;q=0.9,en;q=0.8',
                'Connection: keep-alive',
                'Content-Type: application/json',
                'Cookie: _ga=GA1.2.728367267.1665672802; _gid=GA1.2.343013326.1670594107; locale=es; session_key=7ceae24d013dd694bdbaa06dd8bac781; check_audit=7ceae24d013dd694bdbaa06dd8bac781',
                'Origin: http://www.trackermasgps.com',
                'Referer: http://www.trackermasgps.com/',
                'User-Agent: Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, como Gecko) Chrome/108.0.0.0 Mobile Safari/537.36'
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        $json1 = json_decode($response);
        $direcc = $json1->value;

        $direcc1 = addslashes($direcc);
        $hoy = date("Y-m-d h:i:s");

        $sql = "INSERT INTO lpf (cuenta, id_tracker, `lat`, `long`, `patente`, `direccion`, `fecha`, `last_update`, `imei`, `connection_status`, `grupo`) 
                VALUES ('Pullman', '$id', '$lat', '$lng', '$plate', '$direcc1', '$hoy', '$last_u', '$imei', '$status', '$group')";

        $datosduplicados = mysqli_query($mysqli, "SELECT * FROM lpf WHERE id_tracker='$id'");

        if (mysqli_num_rows($datosduplicados) > 0) {
            $sql1 = "UPDATE lpf SET `lat`='$lat', `long`='$lng', `direccion`='$direcc1', `last_update`='$last_u', `fecha`='$hoy', `cuenta`='Pullman', `imei`='$imei', `connection_status`='$status', `grupo`='$group' WHERE `id_tracker`='$id'";
            $ejecutar1 = mysqli_query($mysqli, $sql1);
            echo "actualizado <br>";
        } else {
            $ejecutar = mysqli_query($mysqli, $sql);
            echo "creado <br>";
        }
    }

    curl_multi_close($mh);
}
