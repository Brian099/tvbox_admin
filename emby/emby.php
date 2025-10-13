<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'emby_api_error.log');
ob_start();

class EmbySpider {
    private $baseUrl = '';
    private $username = '';
    private $password = '';
    private $thread = 0;
    private $header = ["User-Agent: Yamby/1.0.2(Android)"];
    private $cache = [];
    private $cacheDriver = 'memory';
    private $redisConfig = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => '',
        'db' => 0,
        'expire' => 3600
    ];

    public function setCacheDriver($driver) {
        if (in_array($driver, ['memory', 'redis'])) {
            $this->cacheDriver = $driver;
            $this->initCache();
        }
    }

    private function initCache() {
        if ($this->cacheDriver === 'redis' && extension_loaded('redis')) {
            $this->cache = new Redis();
            try {
                $this->cache->connect($this->redisConfig['host'], $this->redisConfig['port'], 5);
                if (!empty($this->redisConfig['password'])) {
                    $this->cache->auth($this->redisConfig['password']);
                }
                $this->cache->select($this->redisConfig['db']);
            } catch (Exception $e) {
                $this->cacheDriver = 'memory';
                $this->cache = [];
                throw new Exception("Redis缓存初始化失败: " . $e->getMessage());
            }
        } else {
            $this->cacheDriver = 'memory';
            $this->cache = [];
        }
    }

    public function init($extend) {
        try {
            $extendDict = json_decode($extend, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("配置JSON解析失败: " . json_last_error_msg());
            }
            if (empty($extendDict['server']) || !filter_var($extendDict['server'], FILTER_VALIDATE_URL)) {
                throw new Exception("Emby服务器地址无效（需完整URL，如http://192.168.1.100:8096）");
            }
            if (empty($extendDict['username'])) {
                throw new Exception("Emby用户名不能为空");
            }
            $this->baseUrl = rtrim($extendDict['server'], '/');
            $this->username = $extendDict['username'];
            $this->password = $extendDict['password'] ?? '';
            
            $this->thread = isset($extendDict['thread']) ? (int)$extendDict['thread'] : 0;
            $this->thread = $this->thread < 0 ? 0 : $this->thread;
            if (isset($extendDict['cache_driver'])) {
                $this->setCacheDriver($extendDict['cache_driver']);
            }
        } catch (Exception $e) {
            $this->baseUrl = '';
            $this->username = '';
            $this->password = '';
            $this->thread = 0;
            throw new Exception("配置初始化失败: " . $e->getMessage());
        }
    }

    public function homeContent($filter) {
        try {
            $embyInfos = $this->getAccessToken();
        } catch (Exception $e) {
            return ['msg' => '获取Emby服务器信息出错: ' . $e->getMessage()];
        }
        $header = array_merge($this->header, ["Content-Type: application/json; charset=UTF-8"]);
        $url = "{$this->baseUrl}/emby/Users/{$embyInfos['User']['Id']}/Views";
        $params = $this->buildCommonParams($embyInfos);
        try {
            $response = $this->httpGet($url, $params, $header);
        } catch (Exception $e) {
            return ['msg' => '获取首页分类失败: ' . $e->getMessage()];
        }
        $typeInfos = $response['Items'] ?? [];
        $classList = [];
        foreach ($typeInfos as $typeInfo) {
            if (strpos($typeInfo['Name'], '播放列表') !== false || strpos($typeInfo['Name'], '相机') !== false) {
                continue;
            }
            $classList[] = [
                "type_name" => $this->cleanText($typeInfo['Name']),
                "type_id" => $typeInfo['Id']
            ];
        }
        return ['class' => $classList];
    }

    public function categoryContent($cid, $page, $filter, $ext) {
        try {
            $embyInfos = $this->getAccessToken();
        } catch (Exception $e) {
            return ['list' => [], 'msg' => '获取Emby服务器信息出错: ' . $e->getMessage()];
        }
        $page = max(1, (int)$page);
        $header = array_merge($this->header, ["Content-Type: application/json; charset=UTF-8"]);
        $url = "{$this->baseUrl}/emby/Users/{$embyInfos['User']['Id']}/Items";
        $params = array_merge($this->buildCommonParams($embyInfos), [
            "SortBy" => "DateLastContentAdded,SortName",
            "IncludeItemTypes" => "Movie,Series",
            "SortOrder" => "Descending",
            "ParentId" => $cid,
            "Recursive" => "true",
            "Limit" => "30",
            "ImageTypeLimit" => 1,
            "StartIndex" => (string)(($page - 1) * 30),
            "EnableImageTypes" => "Primary,Backdrop,Thumb,Banner",
            "Fields" => "BasicSyncInfo,CanDelete,Container,PrimaryImageAspectRatio,ProductionYear,CommunityRating,Status,CriticRating,EndDate,Path",
            "EnableUserData" => "true"
        ]);
        try {
            $response = $this->httpGet($url, $params, $header);
        } catch (Exception $e) {
            return ['list' => [], 'msg' => '获取分类视频失败: ' . $e->getMessage()];
        }
        $videoList = $response['Items'] ?? [];
        $videos = [];
        foreach ($videoList as $video) {
            $videos[] = [
                "vod_id" => $video['Id'],
                "vod_name" => $this->cleanText($video['Name']),
                "vod_pic" => $this->buildImageUrl($video['Id'], $video['ImageTags']['Primary'] ?? ''),
                "vod_remarks" => $this->cleanText($video['ProductionYear'] ?? '')
            ];
        }
        $total = isset($response['TotalRecordCount']) ? (int)$response['TotalRecordCount'] : 0;
        $pageCount = ceil($total / 30);
        return [
            'list' => $videos,
            'page' => $page,
            'pagecount' => $pageCount,
            'limit' => count($videos),
            'total' => $total
        ];
    }

    public function detailContent($did) {
        if (empty($did[0])) {
            return ['list' => [], 'msg' => '视频ID不能为空'];
        }
        try {
            $embyInfos = $this->getAccessToken();
        } catch (Exception $e) {
            return ['list' => [], 'msg' => '获取Emby服务器信息出错: ' . $e->getMessage()];
        }
        $videoId = $did[0];
        $header = array_merge($this->header, ["Content-Type: application/json; charset=UTF-8"]);
        $userID = $embyInfos['User']['Id'];
        $commonParams = $this->buildCommonParams($embyInfos);
        $detailUrl = "{$this->baseUrl}/emby/Users/{$userID}/Items/{$videoId}";
        try {
            $detailResponse = $this->httpGet($detailUrl, $commonParams, $header);
            if (!is_array($detailResponse)) {
                throw new Exception("Emby返回非JSON数据（响应内容前100字符：" . substr(json_encode($detailResponse), 0, 100) . "）");
            }
        } catch (Exception $e) {
            return ['list' => [], 'msg' => '获取视频基础信息失败: ' . $e->getMessage()];
        }
        $videoInfos = $detailResponse;
        $playUrl = '';
        if (isset($videoInfos['IsFolder']) && !$videoInfos['IsFolder']) {
            $videoName = $this->cleanText(str_replace(['#', '$'], ['-', '|'], trim($videoInfos['Name'])));
            $playUrl .= "正片|{$videoName}\${$videoId}";
        } else {
            $seasonUrl = "{$this->baseUrl}/emby/Users/{$userID}/Items";
            $seasonParams = array_merge($commonParams, [
                "ParentId" => $videoId,
                "IncludeItemTypes" => "Season",
                "Recursive" => "false",
                "Fields" => "Name,Id,IndexNumber"
            ]);
            try {
                $seasonResponse = $this->httpGet($seasonUrl, $seasonParams, $header);
            } catch (Exception $e) {
                return ['list' => [], 'msg' => '获取剧集季列表失败: ' . $e->getMessage()];
            }
            $seasons = $seasonResponse['Items'] ?? [];
            foreach ($seasons as $season) {
                $seasonId = $season['Id'];
                $seasonName = $this->cleanText(str_replace(['#', '$'], ['-', '|'], 
                    trim($season['Name'] ?? "第{$season['IndexNumber']}季")));
                $episodeUrl = "{$this->baseUrl}/emby/Users/{$userID}/Items";
                $episodeParams = array_merge($commonParams, [
                    "ParentId" => $seasonId,
                    "IncludeItemTypes" => "Episode",
                    "Recursive" => "false",
                    "Fields" => "Name,Id,IndexNumber"
                ]);
                try {
                    $episodeResponse = $this->httpGet($episodeUrl, $episodeParams, $header);
                } catch (Exception $e) {
                    continue;
                }
                $episodes = $episodeResponse['Items'] ?? [];
                foreach ($episodes as $episode) {
                    $episodeName = $this->cleanText(str_replace(['#', '$'], ['-', '|'], 
                        trim($episode['Name'] ?? "第{$episode['IndexNumber']}集")));
                    $playUrl .= "{$seasonName}|{$episodeName}\${$episode['Id']}#";
                }
            }
            $playUrl = rtrim($playUrl, '#');
        }
        if (empty($videoInfos['Name'])) {
            return ['list' => [], 'msg' => '视频名称缺失（Emby返回数据异常）'];
        }
        if (empty($playUrl)) {
            return ['list' => [], 'msg' => '未生成有效播放地址（视频可能是文件夹但无剧集）'];
        }
        $vod = [
            "vod_id" => $videoId,
            "vod_name" => $this->cleanText($videoInfos['Name'] ?? ''),
            "vod_pic" => $this->buildImageUrl($videoId, $videoInfos['ImageTags']['Primary'] ?? ''),
            "type_name" => $this->cleanText($videoInfos['Genres'][0] ?? ''),
            "vod_year" => $this->cleanText($videoInfos['ProductionYear'] ?? ''),
            "vod_content" => $this->cleanText(str_replace(["\xa0", "\n\n"], [" ", "\n"], $videoInfos['Overview'] ?? '')),
            "vod_play_from" => "EMBY",
            "vod_play_url" => $this->cleanText($playUrl)
        ];
        return ['list' => [$vod]];
    }

    public function searchContent($key, $quick) {
        return $this->searchContentPage($key, $quick, 1);
    }

    public function searchContentPage($keywords, $quick, $page) {
        try {
            $embyInfos = $this->getAccessToken();
        } catch (Exception $e) {
            return ['list' => [], 'msg' => '获取Emby服务器信息出错: ' . $e->getMessage()];
        }
        $page = max(1, (int)$page);
        $header = array_merge($this->header, ["Content-Type: application/json; charset=UTF-8"]);
        $url = "{$this->baseUrl}/emby/Users/{$embyInfos['User']['Id']}/Items";
        $params = array_merge($this->buildCommonParams($embyInfos), [
            "SortBy" => "SortName",
            "SortOrder" => "Ascending",
            "Fields" => "BasicSyncInfo,CanDelete,Container,PrimaryImageAspectRatio,ProductionYear,Status,EndDate",
            "StartIndex" => (string)((($page - 1) * 50)),
            "EnableImageTypes" => "Primary,Backdrop,Thumb",
            "ImageTypeLimit" => "1",
            "Recursive" => "true",
            "SearchTerm" => $this->cleanText($keywords),
            "IncludeItemTypes" => "Movie,Series,BoxSet",
            "GroupProgramsBySeries" => "true",
            "Limit" => "50",
            "EnableTotalRecordCount" => "true"
        ]);
        try {
            $response = $this->httpGet($url, $params, $header);
        } catch (Exception $e) {
            return ['list' => [], 'msg' => '搜索失败: ' . $e->getMessage()];
        }
        $vodList = $response['Items'] ?? [];
        $videos = [];
        foreach ($vodList as $vod) {
            $videos[] = [
                "vod_id" => $vod['Id'],
                "vod_name" => $this->cleanText($vod['Name']),
                "vod_pic" => $this->buildImageUrl($vod['Id'], $vod['ImageTags']['Primary'] ?? ''),
                "vod_remarks" => $this->cleanText($vod['ProductionYear'] ?? '')
            ];
        }
        return ['list' => $videos];
    }

    public function playerContent($flag, $pid, $vipFlags) {
        if (empty($pid)) {
            return ['list' => [], 'msg' => '播放ID不能为空'];
        }
        try {
            $embyInfos = $this->getAccessToken();
        } catch (Exception $e) {
            return ['list' => [], 'msg' => '获取Emby服务器信息出错: ' . $e->getMessage()];
        }
        $requestHeader = array_merge($this->header, ["Content-Type: application/json; charset=UTF-8"]);
        $url = "{$this->baseUrl}/emby/Items/{$pid}/PlaybackInfo";
        $params = array_merge([
            "UserId" => $embyInfos['User']['Id'],
            "IsPlayback" => "false",
            "AutoOpenLiveStream" => "false",
            "StartTimeTicks" => 0,
            "MaxStreamingBitrate" => "2147483647"
        ], $this->buildCommonParams($embyInfos));
        $deviceProfile = '{"DeviceProfile":{"SubtitleProfiles":[{"Method":"Embed","Format":"ass"},{"Format":"ssa","Method":"Embed"},{"Format":"subrip","Method":"Embed"},{"Format":"sub","Method":"Embed"},{"Method":"Embed","Format":"pgssub"},{"Format":"subrip","Method":"External"},{"Method":"External","Format":"sub"},{"Method":"External","Format":"ass"},{"Format":"ssa","Method":"External"},{"Method":"External","Format":"vtt"},{"Method":"External","Format":"ass"},{"Format":"ssa","Method":"External"}],"CodecProfiles":[{"Codec":"h264","Type":"Video","ApplyConditions":[{"Property":"IsAnamorphic","Value":"true","Condition":"NotEquals","IsRequired":false},{"IsRequired":false,"Value":"high|main|baseline|constrained baseline","Condition":"EqualsAny","Property":"VideoProfile"},{"IsRequired":false,"Value":"80","Condition":"LessThanEqual","Property":"VideoLevel"},{"IsRequired":false,"Value":"true","Condition":"NotEquals","Property":"IsInterlaced"}]},{"Codec":"hevc","ApplyConditions":[{"Property":"IsAnamorphic","Value":"true","Condition":"NotEquals","IsRequired":false},{"IsRequired":false,"Value":"high|main|main 10","Condition":"EqualsAny","Property":"VideoProfile"},{"Property":"VideoLevel","Value":"175","Condition":"LessThanEqual","IsRequired":false},{"IsRequired":false,"Value":"true","Condition":"NotEquals","Property":"IsInterlaced"}],"Type":"Video"}],"MaxStreamingBitrate":40000000,"TranscodingProfiles":[{"Container":"ts","AudioCodec":"aac,mp3,wav,ac3,eac3,flac,opus","VideoCodec":"hevc,h264,mpeg4","BreakOnNonKeyFrames":true,"Type":"Video","MaxAudioChannels":"6","Protocol":"hls","Context":"Streaming","MinSegments":2}],"DirectPlayProfiles":[{"Container":"mov,mp4,mkv,hls,webm","Type":"Video","VideoCodec":"h264,hevc,dvhe,dvh1,h264,hevc,hev1,mpeg4,vp9","AudioCodec":"aac,mp3,wav,ac3,eac3,flac,truehd,dts,dca,opus,pcm,pcm_s24le"}],"ResponseProfiles":[{"MimeType":"video/mp4","Type":"Video","Container":"m4v"}],"ContainerProfiles":[],"MusicStreamingTranscodingBitrate":40000000,"MaxStaticBitrate":40000000}}';
        try {
            $response = $this->httpPost($url, $params, $deviceProfile, $requestHeader);
        } catch (Exception $e) {
            return ['list' => [], 'msg' => '获取播放信息失败: ' . $e->getMessage()];
        }
        if (empty($response['MediaSources'][0]['DirectStreamUrl'])) {
            return ['list' => [], 'msg' => '未获取到有效播放地址'];
        }
        $outputHeader = [];
        foreach ($this->header as $headerStr) {
            list($key, $value) = explode(': ', $headerStr, 2);
            $outputHeader[$key] = $value;
        }
        $directStreamUrl = $this->baseUrl . $response['MediaSources'][0]['DirectStreamUrl'];
        return [
            "url" => $directStreamUrl,
            "header" => $outputHeader,
            "parse" => 0
        ];
    }

    public function getAccessToken() {
        if (empty($this->baseUrl) || empty($this->username)) {
            throw new Exception("基础配置未初始化（服务器/用户名缺失）");
        }
        $cacheKey = "emby_" . urlencode($this->baseUrl) . "_" . urlencode($this->username);
        if ($this->cacheDriver === 'redis' && $this->cache->exists($cacheKey)) {
            $cacheData = $this->cache->get($cacheKey);
            $data = json_decode($cacheData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
        if ($this->cacheDriver === 'memory' && isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        $header = array_merge($this->header, ["Content-Type: application/json; charset=UTF-8"]);
        $url = "{$this->baseUrl}/emby/Users/AuthenticateByName";
        $params = [
            "Username" => $this->username,
            "X-Emby-Client" => "Yamby",
            "X-Emby-Device-Name" => "Yamby",
            "X-Emby-Device-Id" => $this->generateUuid(),
            "X-Emby-Client-Version" => "1.0.2"
        ];
        if (!empty($this->password)) {
            $params["Password"] = $this->password;
            $params["Pw"] = $this->password;
        }
        try {
            $response = $this->httpPost($url, $params, '', $header);
        } catch (Exception $e) {
            throw new Exception("Emby认证失败: " . $e->getMessage());
        }
        if (empty($response['AccessToken']) || empty($response['User']['Id'])) {
            throw new Exception("认证响应无效（缺少Token或用户ID）");
        }
        if ($this->cacheDriver === 'redis') {
            $this->cache->setex($cacheKey, $this->redisConfig['expire'], json_encode($response));
        } else {
            $this->cache[$cacheKey] = $response;
        }
        return $response;
    }

    private function cleanText($text) {
        if (empty($text)) {
            return '';
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        return trim($text);
    }

    private function generateUuid() {
        return '123e4567-e89b-12d3-a456-426614174000';
    }

    private function buildCommonParams($embyInfos) {
        return [
            "X-Emby-Client" => $embyInfos['SessionInfo']['Client'],
            "X-Emby-Device-Name" => $embyInfos['SessionInfo']['DeviceName'],
            "X-Emby-Device-Id" => $embyInfos['SessionInfo']['DeviceId'],
            "X-Emby-Client-Version" => $embyInfos['SessionInfo']['ApplicationVersion'],
            "X-Emby-Token" => $embyInfos['AccessToken']
        ];
    }

    private function buildImageUrl($itemId, $imageTag) {
        if (empty($itemId) || empty($imageTag)) {
            return '';
        }
        return "{$this->baseUrl}/emby/Items/{$itemId}/Images/Primary?maxWidth=400&tag={$imageTag}&quality=90";
    }

    private function httpGet($url, $params = [], $header = [], $timeout = 15, $retry = 3) {
        $url .= !empty($params) ? '?' . http_build_query($params) : '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $header
        ]);
        $attempt = 0;
        $response = '';
        $httpCode = 0;
        $error = '';
        do {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            if ($error === '' && $httpCode >= 200 && $httpCode < 300) {
                break;
            }
            $attempt++;
            if ($attempt < $retry) {
                sleep(1);
            }
        } while ($attempt < $retry);
        curl_close($ch);
        if ($error) {
            throw new Exception("GET请求失败（{$url}）: {$error}（重试{$attempt}次）");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("GET请求状态码异常（{$url}）: {$httpCode}（响应内容：" . substr($response, 0, 200) . "）");
        }
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("GET响应JSON解析失败（{$url}）: " . json_last_error_msg() . "（响应内容：" . substr($response, 0, 200) . "）");
        }
        $result['status_code'] = $httpCode;
        return $result;
    }

    private function httpPost($url, $params = [], $data = '', $header = [], $timeout = 15, $retry = 3) {
        $url .= !empty($params) ? '?' . http_build_query($params) : '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $header
        ]);
        $attempt = 0;
        $response = '';
        $error = '';
        do {
            $response = curl_exec($ch);
            $error = curl_error($ch);
            if ($error === '') {
                break;
            }
            $attempt++;
            if ($attempt < $retry) {
                sleep(1);
            }
        } while ($attempt < $retry);
        curl_close($ch);
        if ($error) {
            throw new Exception("POST请求失败（{$url}）: {$error}（重试{$attempt}次）");
        }
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("POST响应JSON解析失败（{$url}）: " . json_last_error_msg() . "（响应内容：" . substr($response, 0, 200) . "）");
        }
        return $result;
    }
}

function filterParam($param) {
    return htmlspecialchars(trim($param ?? ''), ENT_QUOTES, 'UTF-8');
}

$finalResponse = [
    'code' => 1,
    'msg' => '请求未处理'
];

$config = require __DIR__ . '/../config/config.php';
try {
    $pdo = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4", $config['db_user'], $config['db_pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("数据库连接失败: ".$e->getMessage());
}

// 从数据库中获取 Emby 配置 - 通过 SERVER_NAME 区分
try {
    $serverName = filterParam($_GET['server'] ?? '');
    
    if (empty($serverName)) {
        // 如果没有指定 server 参数，获取第一条配置
        $stmt = $pdo->query("SELECT SERVER_NAME, EMBY_SERVER, EMBY_USERNAME, EMBY_PASSWORD FROM emby ORDER BY id ASC LIMIT 1");
        $embyConfig = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // 根据 SERVER_NAME 查询配置
        $stmt = $pdo->prepare("SELECT SERVER_NAME, EMBY_SERVER, EMBY_USERNAME, EMBY_PASSWORD FROM emby WHERE SERVER_NAME = ?");
        $stmt->execute([$serverName]);
        $embyConfig = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$embyConfig) {
            // 如果指定名称不存在，获取第一条配置
            $stmt = $pdo->query("SELECT SERVER_NAME, EMBY_SERVER, EMBY_USERNAME, EMBY_PASSWORD FROM emby ORDER BY id ASC LIMIT 1");
            $embyConfig = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    if (!$embyConfig) {
        die("未找到 Emby 配置信息，请检查数据库中的数据");
    }
    
    $EMBY_SERVER = $embyConfig['EMBY_SERVER'];
    $EMBY_USERNAME = $embyConfig['EMBY_USERNAME'];
    $EMBY_PASSWORD = $embyConfig['EMBY_PASSWORD'];
    $currentServerName = $embyConfig['SERVER_NAME'];
    
} catch (Exception $e) {
    die("获取 Emby 配置失败: " . $e->getMessage());
}

try {
    $embyConfig = [
        'server' => getenv('EMBY_SERVER') ?: $EMBY_SERVER,
        'username' => getenv('EMBY_USERNAME') ?: $EMBY_USERNAME,
        'password' => getenv('EMBY_PASSWORD') ?: $EMBY_PASSWORD,
        'thread' => (int)(getenv('EMBY_THREAD') ?: 0),
        'cache_driver' => getenv('EMBY_CACHE_DRIVER') ?: 'memory'
    ];
    $embySpider = new EmbySpider();
    $embySpider->init(json_encode($embyConfig));
    $embySpider->setCacheDriver($embyConfig['cache_driver']);
    $ac = filterParam($_GET['ac'] ?? '');
    $videoId = filterParam($_GET['ids'] ?? '');
    $cid = filterParam($_GET['t'] ?? '');
    $searchKey = filterParam($_GET['wd'] ?? '');
    $page = max(1, (int)($_GET['pg'] ?? 1));
    $flag = filterParam($_GET['flag'] ?? '');
    $playId = filterParam($_GET['play'] ?? '');

    if ($flag === 'EMBY' && !empty($playId)) {
        $playerData = $embySpider->playerContent('', $playId, '');
        if (isset($playerData['msg'])) {
            throw new Exception($playerData['msg']);
        }
        $finalResponse = [
            'url' => $playerData['url'],
            'header' => $playerData['header'],
            'parse' => $playerData['parse']
        ];
    } elseif (!empty($searchKey)) {
        $searchData = $embySpider->searchContentPage($searchKey, false, $page);
        $finalResponse = [
            'code' => 0,
            'msg' => empty($searchData['list']) ? '未找到关键词「' . $searchKey . '」相关内容' : '搜索成功（关键词：' . $searchKey . '，页码：' . $page . '）',
            'list' => $searchData['list'] ?? []
        ];
    } elseif ($ac === 'detail' && !empty($videoId)) {
        $detailData = $embySpider->detailContent([$videoId]);
        if (isset($detailData['msg'])) {
            throw new Exception($detailData['msg']);
        }
        if (empty($detailData['list'])) {
            throw new Exception("未找到ID为「{$videoId}」的视频");
        }
        $finalResponse = [
            'code' => 0,
            'list' => $detailData['list']
        ];
    } elseif ($ac === 'detail' && !empty($cid)) {
        $categoryData = $embySpider->categoryContent($cid, $page, '', '');
        if (isset($categoryData['msg'])) {
            throw new Exception($categoryData['msg']);
        }
        $finalResponse = [
            'code' => 0,
            'msg' => '分类列表获取成功（ID：' . $cid . '，页码：' . $page . '）',
            'list' => $categoryData['list'] ?? [],
            'page' => $categoryData['page'] ?? $page,
            'pagecount' => $categoryData['pagecount'] ?? 1,
            'total' => $categoryData['total'] ?? 0
        ];
    } else {
        $homeData = $embySpider->homeContent('');
        if (isset($homeData['msg'])) {
            throw new Exception($homeData['msg']);
        }
        $finalResponse = [
            'code' => 0,
            'msg' => '首页分类获取成功',
            'class' => $homeData['class'] ?? []
        ];
    }
    error_log(
        date('Y-m-d H:i:s') . " | NORMAL RESPONSE | SERVER: {$currentServerName} | " . json_encode($finalResponse, JSON_UNESCAPED_UNICODE) . "\n",
        3,
        'emby_api_response.log'
    );
} catch (Exception $e) {
    error_log(
        date('Y-m-d H:i:s') . " | ERROR | SERVER: {$currentServerName} | " . $e->getMessage() . 
        " | 行号：" . $e->getLine() . " | IP：" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n",
        3,
        'emby_api_error.log'
    );
    $finalResponse = [
        'code' => 1,
        'msg' => '请求失败: ' . $e->getMessage(),
        'error_line' => $e->getLine()
    ];
    error_log(
        date('Y-m-d H:i:s') . " | ERROR RESPONSE | SERVER: {$currentServerName} | " . json_encode($finalResponse, JSON_UNESCAPED_UNICODE) . "\n",
        3,
        'emby_api_response.log'
    );
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
$jsonStr = json_encode($finalResponse, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
if ($jsonStr === false) {
    $errorMsg = 'JSON编码失败: ' . json_last_error_msg();
    $errorData = substr(print_r($finalResponse, true), 0, 300);
    echo json_encode([
        'code' => 1,
        'msg' => $errorMsg,
        'error_data' => $errorData
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo $jsonStr;
}
flush();
exit;
?>