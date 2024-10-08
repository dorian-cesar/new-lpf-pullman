<?php
set_time_limit(1200);

include "conexion.php";

$user="Pullman";
$pasw="123";
$name='Pullman';

$consulta = "SELECT hash FROM masgps.hash WHERE user='$user' AND pasw='$pasw'";
$resultado = mysqli_query($mysqli, $consulta);

if (!$resultado) {
    die("Error en la consulta del hash: " . mysqli_error($mysqli));
}

$data = mysqli_fetch_array($resultado);
$hash = $data['hash'];

date_default_timezone_set("America/Santiago");
$hoy = date("Y-m-d");

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
    'Content-Type: application/json'
  ),
));

$response2 = curl_exec($curl);
$json = json_decode($response2);
$array = $json->list;

curl_close($curl);

$batchSize = 20;
$totalItems = count($array);

for ($i = 0; $i < $totalItems; $i += $batchSize) {
    // Crea el lote de 20 elementos
    $batch = array_slice($array, $i, $batchSize);
    
    // Inicia multi-cURL
    $multiCurl = curl_multi_init();
    $curlHandles = [];
    $responses = [];

    foreach ($batch as $key => $item) {
        $id = $item->id;
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
            CURLOPT_HTTPHEADER => array(
              'Content-Type: application/json'
            ),
        ));
        
        $curlHandles[$key] = $ch;
        curl_multi_add_handle($multiCurl, $ch);
    }

    // Ejecuta todas las consultas
    do {
        $status = curl_multi_exec($multiCurl, $active);
    } while ($active && $status == CURLM_OK);

    // Recoge las respuestas
    foreach ($curlHandles as $key => $ch) {
        $responses[$key] = curl_multi_getcontent($ch);
        curl_multi_remove_handle($multiCurl, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiCurl);

    // Procesa las respuestas
    foreach ($batch as $key => $item) {
        $response2 = $responses[$key];
        $json2 = json_decode($response2);
        
        $id = $item->id;
        $imei = $item->source->device_id;
        $group = $item->group_id;
        $lat = $json2->state->gps->location->lat;
        $lng = $json2->state->gps->location->lng;
        $last_u = $json2->state->last_update;
        $plate = $item->label;
        $status = $json2->state->connection_status;

        // Consulta geocoder
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
              'Content-Type: application/json'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);

        $json1 = json_decode($response);
        $direcc = addslashes($json1->value);

        // Inserta o actualiza en la base de datos
        $datosduplicados = mysqli_query($mysqli, "SELECT * FROM lpf WHERE id_tracker='$id'");
        $hoy = date("Y-m-d h:i:s ");

        if (mysqli_num_rows($datosduplicados) > 0) {
            $sql1 = "UPDATE lpf SET `lat`='$lat', `long`='$lng', `direccion`='$direcc', `last_update`='$last_u', `fecha`='$hoy', `cuenta`='Pullman', `imei`='$imei', `connection_status`='$status', `grupo`='$group' WHERE `id_tracker`='$id'";
            mysqli_query($mysqli, $sql1);
            echo "Actualizado ID: $id <br>";
        } else {
            $sql = "INSERT INTO lpf (cuenta, id_tracker, `lat`, `long`, `patente`, `direccion`, `fecha`, `last_update`, `imei`, `connection_status`, `grupo`) VALUES ('Pullman', '$id', '$lat', '$lng', '$plate', '$direcc', '$hoy', '$last_u', '$imei', '$status', '$group')";
            mysqli_query($mysqli, $sql);
            echo "Creado ID: $id <br>";
        }
    }
}
