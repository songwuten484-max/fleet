<?php
require_once __DIR__.'/config.php';

/* Haversine distance in meters between two lat-lng points */
function haversine_m($lat1,$lng1,$lat2,$lng2){
    $R = 6371000.0;
    $dLat = deg2rad($lat2-$lat1);
    $dLng = deg2rad($lng2-$lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

/* Simple circle geofence; if polygon provided, use ray-casting */
function is_outside_geofence($lat,$lng){
    $poly = GEOFENCE_POLYGON;
    if ($poly) {
        $coords = json_decode($poly, true);
        if (is_array($coords) && count($coords)>2) {
            return !point_in_polygon($lng,$lat,$coords); // coords are [lng,lat]
        }
    }
    $d = haversine_m(GEOFENCE_CENTER_LAT,GEOFENCE_CENTER_LNG,$lat,$lng);
    return $d > GEOFENCE_RADIUS_M;
}

/* Ray casting: coords as array of [lng,lat] */
function point_in_polygon($x,$y,$poly){
    $inside = false; $j = count($poly)-1;
    for ($i=0; $i<count($poly); $i++){
        $xi=$poly[$i][0]; $yi=$poly[$i][1];
        $xj=$poly[$j][0]; $yj=$poly[$j][1];
        $intersect = (($yi>$y) != ($yj>$y)) && ($x < ($xj-$xi)*($y-$yi)/($yj-$yi + 1e-12) + $xi);
        if ($intersect) $inside = !$inside;
        $j=$i;
    }
    return $inside;
}
?>