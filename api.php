<?php
// api.php

header('Content-Type: application/json; charset=utf-8');

// 配置文件路径
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error'=>'系统未初始化']);
    exit;
}
$config = require $configFile;

// 连接数据库
try {
    $pdo = new PDO(
        "mysql:host=".$config['db_host'].";dbname=".$config['db_name'].";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error'=>'数据库连接失败']);
    exit;
}

// 允许的 package_name
$package_name_OK = "com.fongmi.android.tv";

// 获取参数
$package_name = isset($_GET['package_name']) ? trim($_GET['package_name']) : '';
$deviceId     = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';

if ($package_name !== $package_name_OK) {
    echo json_encode(['error'=>'参数错误']);
    exit;
}

$serverFile = '';

// 查找设备
if ($deviceId !== '') {
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE device_id = :device_id LIMIT 1");
    $stmt->execute([':device_id'=>$deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($device) {
        $serverFile = trim($device['server']);

        // 更新 last_login
        $stmt = $pdo->prepare("UPDATE devices SET last_login = :last_login WHERE device_id = :device_id");
        $stmt->execute([
            ':last_login'=>date('Y-m-d H:i:s'),
            ':device_id'=>$deviceId
        ]);
    } else {
        // 新设备插入，expire_at 默认为今天+7天
        $expireAt = date('Y-m-d', strtotime('+7 days'));
        $stmt = $pdo->prepare("INSERT INTO devices (device_id, created_at, last_login, server, remark, expire_at) VALUES (:device_id, :created_at, :last_login, '', '', :expire_at)");
        $stmt->execute([
            ':device_id'=>$deviceId,
            ':created_at'=>date('Y-m-d H:i:s'),
            ':last_login'=>date('Y-m-d H:i:s'),
            ':expire_at'=>$expireAt
        ]);
    }
}

// 计算域名
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && $_SERVER['HTTP_FRONT_END_HTTPS'] !== 'off')
            ) ? 'https' : 'http';
$domain = $protocol . '://' . $_SERVER['HTTP_HOST'];

// 返回 JSON
if ($serverFile !== '') {
    $stmt = $pdo->prepare("SELECT repos, attach_local FROM duocang_data WHERE ServerName = :ServerName LIMIT 1");
    $stmt->execute([':ServerName'=>$serverFile]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $result = [];

        // 处理原有 repos
        if (!empty($row['repos'])) {
			$repoNames = array_filter(explode(';', $row['repos']));
			if (!empty($repoNames)) {
				$placeholders = implode(',', array_fill(0, count($repoNames), '?'));
				$stmt2 = $pdo->prepare("SELECT name, url FROM repos WHERE name IN ($placeholders)");
				$stmt2->execute($repoNames);
				$reposData = $stmt2->fetchAll(PDO::FETCH_ASSOC);

				// 按 duocang_data.repos 顺序排序
				$reposMap = [];
				foreach ($reposData as $r) {
					$reposMap[$r['name']] = $r['url'];
				}

				foreach ($repoNames as $name) {
					if (isset($reposMap[$name])) {
						$result[] = [
							'name' => $name,
							'url'  => $reposMap[$name]
						];
					}
				}
			}
		}


        // 如果 attach_local=1，则附加本地资源
        if (!empty($row['attach_local'])) {
            // 获取 local_files 表中的文件名
            $stmt3 = $pdo->query("SELECT filename FROM local_files");
            $localFiles = $stmt3->fetchAll(PDO::FETCH_COLUMN);
            $targets = array_flip($localFiles); // 提高查找效率

            $baseDir = __DIR__ . '/local_repo';

            // 遍历 local_repo
            $scanLocal = function($dir) use (&$scanLocal, $targets, &$result, $baseDir, $domain) {
                $files = scandir($dir);
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $path = $dir . '/' . $file;
                    if (is_dir($path)) {
                        $scanLocal($path);
                    } else {
                        if (isset($targets[$file])) {
                            $parentDir = basename(dirname($path));
                            $relativePath = str_replace($baseDir, '', $path);
                            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
                            $relativePath = ltrim($relativePath, '/');
                            $result[] = [
                                'name' => $parentDir,
                                'url'  => $domain . '/local_repo/' . $relativePath
                            ];
                        }
                    }
                }
            };

            $scanLocal($baseDir);
        }

        echo json_encode(['urls'=>$result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } else {
        echo json_encode([
            "error"  => "对应的 server 配置不存在",
            "server" => $serverFile
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    echo json_encode([
        "error"     => "未配置 server",
        "device_id" => $deviceId
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
