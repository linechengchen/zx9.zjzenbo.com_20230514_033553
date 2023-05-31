<?php



$router->match(['get', 'post'], 'site/config/setting', 'ConfigController@setting');
$router->match(['get', 'post'], 'site/config/area', 'AreaController@index');
$router->match(['get', 'post'], 'site/config/area/add', 'AreaController@add');
$router->match(['get', 'post'], 'site/config/area/edit', 'AreaController@edit');
$router->match(['post'], 'site/config/area/delete', 'AreaController@delete');
$router->match(['get'], 'site/config/area/show', 'AreaController@show');
$router->match(['post'], 'site/config/area/sort', 'AreaController@sort');





