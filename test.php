<?php
function custom_base64_encode($data) {
    $b64 = base64_encode($data);
    // 自定义表（可调整）
    return strtr($b64, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', 'QWERTYUIOPASDFGHJKLZXCVBNMpoiuytrewqasdfghjklmnbvcxz1234567890-_');
}

// 随机从干扰符数组中选择一个插入
function insert_noise($str, $noiseArray = ['#', '@', '&', '%', '*'], $interval = 5) {
    $out = '';
    $len = strlen($str);
    $noiseCount = count($noiseArray);
    for ($i = 0; $i < $len; $i++) {
        $out .= $str[$i];
        // 每隔 interval 位插入一个随机干扰符
        if (($i+1) % $interval == 0 && $i+1 != $len) {
            $out .= $noiseArray[random_int(0, $noiseCount - 1)];
        }
    }
    return $out;
}

function obfuscate_payload($data) {
    $b64 = custom_base64_encode($data);
    $rev = strrev($b64); // 反转字符串
    $noisy = insert_noise($rev, ['#', '@', '&', '%', '*'], 5); // 多种干扰符
	$noisy = bin2hex($noisy);
    return $noisy;
}

// 用法示例
$payload = json_encode(['urls'=>[['name'=>'foo','url'=>'bar']]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$mix = obfuscate_payload($payload);
echo $mix;