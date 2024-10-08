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

// Hacer la consulta inicial para obtener la lista de trackers
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
        'Origin: http://www.trackermasgps.com',
        'Referer: http://www.trackermasgps.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36'
    ),
));

$response = curl_exec($curl);
curl_close($curl);

$json = json_decode($response);
$array = $json->list;

$tracker_batches = array_chunk($array, 20); // Dividimos en lotes de 20

foreach ($tracker_batches as $batch) {
    $multiCurl = [];
    $result = [];
    $mh = curl_multi_init();

    foreach ($batch as $item) {
        $id = $item->id;
        $plate = $item->label;

        $ch = curl_init();

        curl_setopt_array($ch, array(
            CURLOPT_URL => 'http://www.trackermasgps.com/api-v2/tracker/get_state',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '{"hash": "' . $hash . '", "tracker_id": ' . $id . '}',
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        ));

        $multiCurl[] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    // Ejecutar las solicitudes en paralelo
    $running = null;
    do {
        curl_multi_exec($mh, $running);
    } while ($running);

    // Recopilar las respuestas
    foreach ($multiCurl as $ch) {
        $response = curl_multi_getcontent($ch);
        $json2 = json_decode($response);

        $lat = $json2->state->gps->location->lat ?? null;
        $lng = $json2->state->gps->location->lng ?? null;
        $last_u = $json2->state->last_update ?? null;
        $status = $json2->state->connection_status ?? null;
        $imei = $item->source->device_id ?? null;
        $group = $item->group_id ?? null;
        $direcc = "";

        // Otra consulta para obtener la dirección
        $ch2 = curl_init();
        curl_setopt_array($ch2, array(
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
                'Content-Type: application/json',
            ),
        ));
        $response3 = curl_exec($ch2);
        curl_close($ch2);

        $json3 = json_decode($response3);
        $direcc = $json3->value ?? '';

        $direcc1 = addslashes($direcc);
        $hoy = date("Y-m-d h:i:s");

        // Insertar o actualizar la base de datos
        $sql = "INSERT INTO lpf (cuenta, id_tracker, lat, `long`, patente, direccion, fecha, last_update, imei, connection_status, grupo)
                VALUES ('Pullman', '$id', '$lat', '$lng', '$plate', '$direcc1', '$hoy', '$last_u', '$imei', '$status', '$group')
                ON DUPLICATE KEY UPDATE lat='$lat', `long`='$lng', direccion='$direcc1', last_update='$last_u', fecha='$hoy', imei='$imei', connection_status='$status', grupo='$group'";

        mysqli_query($mysqli, $sql);
        curl_multi_remove_handle($mh, $ch);
    }

    curl_multi_close($mh);
}

?>
