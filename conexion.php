<?php
//Conexion clase MYSQL
// $obtejoconexion= new MYSQLI(servidor, usuario, password, basedatos);
//$mysqli = new mysqli('ls-233cbb9833b65f1d81c7123bc6f953c2597da307.cwdutvqwihot.us-east-1.rds.amazonaws.com','dbmasteruser','J&ye#Ve3*f8dYR~XP~8V(uAj0%Xs|Pe2','masgps-telemetria');
//$mysqli = new mysqli('162.213.251.110','pionjmhy_lpf','Ct(e}tX6bb;5','pionjmhy_masgps-bi');
$mysqli = new mysqli('ls-3c0c538286def4da7f8273aa5531e0b6eee0990c.cylsiewx0zgx.us-east-1.rds.amazonaws.com','dbmasteruser','eF5D;6VzP$^7qDryBzDd,`+w(5e4*qI+','masgps');
if($mysqli->connect_errno >0){
    die("Error en la conexión". $mysqli->connect_error);
}