<?php
set_time_limit(1200);
include "conexion.php";

$user = "Pullman";
$pasw = "123";
$name = 'Pullman';

// Obtener el hash
$consulta = "SELECT hash FROM masgps.hash WHERE user = ? AND pasw = ?";
$stmt = $mysqli->prepare($consulta);
$stmt->bind_param("ss", $user, $pasw);
$stmt->execute();
$resultado = $stmt->get_result();

if (!$resultado || $resultado->num_rows == 0) {
    die("Error en la consulta del hash: " . $mysqli->error);
}

$data = $resultado->fetch_assoc();
echo $hash = $data['hash'];

date_default_timezone_set("America/Santiago");
$hoy = date("Y-m-d");

// Inicializar CURL
$curl = curl_init();
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_TIMEOUT, 0);
curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

// Función para realizar peticiones CURL
function ejecutarCurl($url, $postData, $headers) {
    global $curl;
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    return curl_exec($curl);
}

// Obtener lista de trackers
$response = ejecutarCurl(
    'http://www.trackermasgps.com/api-v2/tracker/list',
    '{"hash": "' . $hash . '"}',
    ['Content-Type: application/json']
);

$json = json_decode($response);
if (!$json || empty($json->list)) {
    die("Error al obtener la lista de trackers.");
}

foreach ($json->list as $item) {
    $id = $item->id;
    $imei = $item->source->device_id;
    $group = $item->group_id;
    $plate = $item->label;

    // Obtener estado del tracker
    $responseState = ejecutarCurl(
        'http://www.trackermasgps.com/api-v2/tracker/get_state',
        '{"hash": "' . $hash . '", "tracker_id": ' . $id . '}',
        ['Content-Type: application/json']
    );

    $json2 = json_decode($responseState);
    if (!$json2 || empty($json2->state)) {
        continue; // Si no hay estado, continuar con el siguiente tracker
    }

    $lat = $json2->state->gps->location->lat;
    $lng = $json2->state->gps->location->lng;
    $last_u = $json2->state->last_update;
    $status = $json2->state->connection_status;

    // Obtener dirección
    $responseLocation = ejecutarCurl(
        'http://www.trackermasgps.com/api-v2/geocoder/search_location',
        '{"location":{"lat":' . $lat . ',"lng":' . $lng . '},"lang":"es","hash":"' . $hash . '"}',
        ['Content-Type: application/json']
    );

    $json1 = json_decode($responseLocation);
    $direcc = addslashes($json1->value ?? ''); // Validar si existe el valor de dirección

    // Insertar o actualizar en la base de datos
    $hoy = date("Y-m-d h:i:s");

    $stmtCheck = $mysqli->prepare("SELECT * FROM lpf WHERE id_tracker = ?");
    $stmtCheck->bind_param("i", $id);
    $stmtCheck->execute();
    $resultCheck = $stmtCheck->get_result();

    if ($resultCheck->num_rows > 0) {
        // Actualizar si ya existe
        $sqlUpdate = "UPDATE lpf SET lat = ?, `long` = ?, direccion = ?, last_update = ?, fecha = ?, cuenta = ?, imei = ?, connection_status = ?, grupo = ? WHERE id_tracker = ?";
        $stmtUpdate = $mysqli->prepare($sqlUpdate);
        $stmtUpdate->bind_param("ddssssssii", $lat, $lng, $direcc, $last_u, $hoy, $user, $imei, $status, $group, $id);
        $stmtUpdate->execute();
        echo "Actualizado<br>";
    } else {
        // Insertar si no existe
        $sqlInsert = "INSERT INTO lpf (cuenta, id_tracker, lat, `long`, patente, direccion, fecha, last_update, imei, connection_status, grupo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtInsert = $mysqli->prepare($sqlInsert);
        $stmtInsert->bind_param("siddsssssii", $user, $id, $lat, $lng, $plate, $direcc, $hoy, $last_u, $imei, $status, $group);
        $stmtInsert->execute();
        echo "Creado<br>";
    }
}

curl_close($curl);
?>
