<?php

define('ZOOM_FACTOR', 2);
define('MOVE_FACTOR', 0.40);

// enforce a minimum range in feet (their units) for map context
define('MIN_MAP_CONTEXT', 250);

define('MAP_PHOTO_SERVER', 'http://map.harvard.edu/mapserver/images/bldg_photos/');

// don't show these fields in detail page
$detailBlacklist = array('Root', 'Shape', 'PHOTO_FILE', 'OBJECTID', 'FID', 'BL_ID');

$name = $_REQUEST['selectvalues'];

$details = $_REQUEST['info'];
if (!isset($_REQUEST['tab']))
  $_REQUEST['tab'] = 'Map';

$tab = $_REQUEST['tab'];

if ($tab == 'Map') {
  require_once LIBDIR . '/WMSServer.php';
  $wms = new WMSServer(WMS_SERVER);
  $bbox = isset($_REQUEST['bbox']) ? bboxStr2Arr($_REQUEST['bbox']) : NULL;

  switch ($page->branch) {
   case 'Webkit':
     $imageWidth = 290; $imageHeight = 190;
     break;
   case 'Touch':
     $imageWidth = 200; $imageHeight = 200;
     break;
   case 'Basic':
     if ($page->platform == 'bbplus') {
       $imageWidth = 300; $imageHeight = 300;
     } else {
       $imageWidth = 200; $imageHeight = 200;
     }
     break;
  }

  if (!$bbox) {
    require_once LIBDIR . '/ArcGISServer.php';
    if (strpos($name, ',') !== FALSE) {
        $nameparts = explode(',', $name);
	$name = $nameparts[0];
    }
    $name = str_replace('.', '', $name);

    // if we're looking at Dining, search the Dining collection not default
    if ($_REQUEST['category'] == 'Dining') {
      $searchResults = ArcGISServer::search($name, 'Dining');
    } else {
      $searchResults = ArcGISServer::search($name);
    }

    if ($searchResults && $searchResults->results) {
      $result = $searchResults->results[0];
      foreach ($result->attributes as $field => $value) {
        $details[$field] = $value;
      }

      switch ($result->geometryType) {
       case 'esriGeometryPolygon':
         $rings = $result->geometry->rings;
         $xmin = PHP_INT_MAX;
         $xmax = 0;
         $ymin = PHP_INT_MAX;
         $ymax = 0;
         foreach ($rings[0] as $point) {
           if ($xmin > $point[0]) $xmin = $point[0];
           if ($xmax < $point[0]) $xmax = $point[0];
           if ($ymin > $point[1]) $ymin = $point[1];
           if ($ymax < $point[1]) $ymax = $point[1];
         }

	 $xrange = $xmax - $xmin;
	 if ($xrange < MIN_MAP_CONTEXT) {
	   $xmax += (MIN_MAP_CONTEXT - $xrange) / 2;
	   $xmin -= (MIN_MAP_CONTEXT - $xrange) / 2;
         }
	 $yrange = $ymax - $ymin;
	 if ($yrange < 200) {
	   $ymax += (MIN_MAP_CONTEXT - $yrange) / 2;
	   $ymin -= (MIN_MAP_CONTEXT - $yrange) / 2;
         }

         break;
       case 'esriGeometryPoint':
       default:
         $pointBuffer = MIN_MAP_CONTEXT / 2;
         $xmin = $result->geometry->x - $pointBuffer;
         $xmax = $result->geometry->x + $pointBuffer;
         $ymin = $result->geometry->y - $pointBuffer;
         $ymax = $result->geometry->y + $pointBuffer;
         break;
      }
    
      $minBBox = array(
        'xmin' => $xmin,
        'ymin' => $ymin,
        'xmax' => $xmax,
        'ymax' => $ymax,
        );
  
      $bbox = $wms->calculateBBox($imageWidth, $imageHeight, $minBBox);

    } else { // no search results
      $imageUrl = 'images/map_not_found_placeholder.jpg';
    }

  }

  if ($bbox) {
    $imageUrl = $wms->getMap($imageWidth, $imageHeight, 'EPSG:2249', $bbox);

    // build urls for panning/zooming
    $params = $_GET;

    $params['bbox'] = bboxArr2Str(shiftBBox($bbox, 0, -1, 0));
    $scrollNorth = 'detail.php?' . http_build_query($params);
    $params['bbox'] = bboxArr2Str(shiftBBox($bbox, 0, 1, 0));
    $scrollSouth = 'detail.php?' . http_build_query($params);
    $params['bbox'] = bboxArr2Str(shiftBBox($bbox, 1, 0, 0));
    $scrollEast = 'detail.php?' . http_build_query($params);
    $params['bbox'] = bboxArr2Str(shiftBBox($bbox, -1, 0, 0));
    $scrollWest = 'detail.php?' . http_build_query($params);
    $params['bbox'] = bboxArr2Str(shiftBBox($bbox, 0, 0, 1));
    $zoomInUrl = 'detail.php?' . http_build_query($params);
    $params['bbox'] = bboxArr2Str(shiftBBox($bbox, 0, 0, -1));
    $zoomOutUrl = 'detail.php?' . http_build_query($params);
  }

  // the following are only used by webkit version
  $mapBaseURL = $wms->getMapBaseUrl();
  $mapOptions = '&' . http_build_query(array(
    'crs' => 'EPSG:2249',
    ));
}

$selectvalue = $_REQUEST['selectvalues'];

$tabs = new Tabs(selfURL($details), "tab", array("Map", "Photo", "Details"));

if (array_key_exists('PHOTO_FILE', $details)) {
  $photoURL = MAP_PHOTO_SERVER . $details['PHOTO_FILE'];

  // all photos returned are 300px wide but variable height
  if ($page->platform == 'bbplus') {
    $photoWidth = '300';
  } else {
    $photoWidth = '90%';
  }

} else {
  $tabs->hide("Photo");
}

$displayDetails = array();
foreach ($details as $field => $value) {
  if (!in_array($field, $detailBlacklist))
    $displayDetails[$field] = $value;
}

$tabs_html = $tabs->html($page->branch);

require "$page->branch/detail.html";

$page->output();



function selfURL($details) {
  $params = $_GET;
  $params['info'] = array_merge($params['info'], $details);
  unset($params['tab']);
  return 'detail.php?' . http_build_query($params);
}

function bboxArr2Str($bbox) {
  return implode(',', array_values($bbox));
}

function bboxStr2Arr($bboxStr) {
  $values = explode(',', $bboxStr);
  return array(
    'xmin' => $values[0],
    'ymin' => $values[1],
    'xmax' => $values[2],
    'ymax' => $values[3],
    );
}

// all args can be -1, 0, or 1
function shiftBBox($bbox, $east, $south, $in) {
  $xrange = $bbox['xmax'] - $bbox['xmin'];
  $yrange = $bbox['ymax'] - $bbox['ymin'];
  if ($east != 0) {
    $bbox['xmin'] += $east * $xrange * MOVE_FACTOR;
    $bbox['xmax'] += $east * $xrange * MOVE_FACTOR;
  }
  if ($south != 0) {
    $bbox['ymin'] += $south * $yrange * MOVE_FACTOR;
    $bbox['ymax'] += $south * $yrange * MOVE_FACTOR;
  }
  if ($in != 0) {
    if ($in == 1)
      $inset = (ZOOM_FACTOR - 1) / ZOOM_FACTOR;
    else
      $inset = -(ZOOM_FACTOR - 1);

    $bbox['xmin'] += ($xrange / 2) * $inset;
    $bbox['ymin'] += ($yrange / 2) * $inset;
    $bbox['xmax'] -= ($xrange / 2) * $inset;
    $bbox['ymax'] -= ($yrange / 2) * $inset;
  }

  return $bbox;
}

?>