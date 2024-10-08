<?php

set_time_limit(1200);

include __DIR__."/conexion.php";

$user="Pullman";
$pasw="123";
$name='Pullman';

// Obtener el hash desde la base de datos
$consulta = "SELECT hash FROM masgps.hash WHERE user='$user' AND pasw='$pasw'";
$resultado = mysqli_query($mysqli, $consulta);

if (!$resultado) {
    die("Error en la consulta del hash: " . mysqli_error($mysqli));
}

$data = mysqli_fetch_array($resultado);
echo
$hash = $data['hash'];

date_default_timezone_set("America/Santiago");
$hoy = date("Y-m-d");

// Consultar el listado de trackers
$curl = curl_init();
curl_setopt_array($curl, array(
    CURLOPT_URL => 'http://www.trackermasgps.com/api-v2/tracker/list',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => '{"hash":"' . $hash . '"}',
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json'
    ),
));
$response2 = curl_exec($curl);
curl_close($curl);

$json = json_decode($response2);
$array = $json->list;

// Procesar los trackers en lotes de 20
$loteSize = 20;
$chunks = array_chunk($array, $loteSize);

foreach ($chunks as $lote) {
    $multiCurl = [];
    $mh = curl_multi_init();

    // Configurar cada cURL individualmente
    foreach ($lote as $item) {
        $id = $item->id;

        // Preparar la solicitud de estado del tracker
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'http://www.trackermasgps.com/api-v2/tracker/get_state',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => '{"hash": "' . $hash . '", "tracker_id": ' . $id . '}',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $multiCurl[$id] = $curl;
        curl_multi_add_handle($mh, $curl);
    }

    // Ejecutar las consultas en paralelo
    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($active > 0);

    // Procesar las respuestas
    foreach ($multiCurl as $id => $curl) {
        $response2 = curl_multi_getcontent($curl);
        curl_multi_remove_handle($mh, $curl);
        curl_close($curl);

        $json2 = json_decode($response2);
        $lat = $json2->state->gps->location->lat ?? null;
        $lng = $json2->state->gps->location->lng ?? null;
        $last_u = $json2->state->last_update ?? null;
        $plate = $item->label;
        $status = $json2->state->connection_status ?? null;
        $imei = $item->source->device_id;
        $group = $item->group_id;

        // Consultar la dirección de cada tracker
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'http://www.trackermasgps.com/api-v2/geocoder/search_location',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => '{"location":{"lat":' . $lat . ',"lng":' . $lng . '},"lang":"es","hash":"' . $hash . '"}',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);

        $json1 = json_decode($response);
        $direcc = addslashes($json1->value ?? '');

        // Verificar si el tracker ya existe en la base de datos
        $datosduplicados = mysqli_query($mysqli, "SELECT * FROM lpf WHERE id_tracker='$id'");

        if (mysqli_num_rows($datosduplicados) > 0) {
            // Actualizar si el tracker ya existe
            $sql1 = "UPDATE lpf SET `lat`='$lat', `long`='$lng', `direccion`='$direcc', `last_update`='$last_u', `fecha`='$hoy', 
                     `cuenta`='Pullman', `imei`='$imei', `connection_status`='$status', `grupo`='$group' WHERE `id_tracker`='$id'";
            mysqli_query($mysqli, $sql1);
            echo "Tracker $id actualizado <br>";
        } else {
            // Insertar si es un nuevo tracker
            $sql = "INSERT INTO lpf (cuenta,id_tracker,`lat`,`long`,`patente`,`direccion`,`fecha`,`last_update`, `imei`,`connection_status`,`grupo`) 
                    VALUES ('Pullman','$id', '$lat', '$lng', '$plate', '$direcc', '$hoy','$last_u','$imei', '$status','$group')";
            mysqli_query($mysqli, $sql);
            echo "Tracker $id creado <br>";
        }
    }

    // Cerrar el manejador de multi-cURL
    curl_multi_close($mh);
}

