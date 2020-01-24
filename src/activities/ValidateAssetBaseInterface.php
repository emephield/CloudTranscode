
<?php

require_once __DIR__.'/BasicActivity.php';

use SA\CpeSdk;

class ValidateAssetActivity extends BasicActivity
{
    private $finfo;
    private $s3;
    private $curl_data = '';
    
    public function __construct($client = null, $params, $debug, $cpeLogger)
    {
        var_dump($params);
        # Check if preper env vars are setup
        if (!($region = getenv("AWS_DEFAULT_REGION")))
            throw new CpeSdk\CpeException("Set 'AWS_DEFAULT_REGION' environment variable!");
        
        parent::__construct($client, $params, $debug, $cpeLogger);
        
        $this->s3 = new \Aws\S3\S3Client([
            "version" => "latest",
            "region"  => $region
        ]);
    }

    // Used to limit the curl download in case of an HTTP encode
    private function writefn($ch, $chunk)
    {
        static $limit = 1024; // 500 bytes, it's only a test
        
        $len = strlen($this->curl_data) + strlen($chunk);
        if ($len >= $limit ) {
            $this->curl_data .= substr($chunk, 0, $limit-strlen($this->curl_data));
            return -1;
        }
        
        $this->curl_data .= $chunk;
        return strlen($chunk);
    }
    
    // Perform the activity
    public function process($task)
    {
        $this->cpeLogger->logOut(
            "INFO",
            basename(__FILE__),
            "Preparing Asset validation ...",
            $this->logKey
        );

        // Call parent process:
        parent::process($task);
        
        $this->activityHeartbeat();
        $tmpFile = tempnam(sys_get_temp_dir(), 'ct');

        if (isset($this->input->{'input_asset'}->{'http'})) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->input->{'input_asset'}->{'http'});
            curl_setopt($ch, CURLOPT_RANGE, '0-1024');
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, array($this, 'writefn'));
            curl_exec($ch);
            curl_close($ch);
            $chunk = $this->curl_data;
        }
        else if (isset($this->input->{'input_asset'}->{'bucket'}) &&
                 isset($this->input->{'input_asset'}->{'file'})) {
            // Fetch first 1 KiB of the file for Magic number validation
            $obj = $this->s3->getObject([
                'Bucket' => $this->input->{'input_asset'}->{'bucket'},
                'Key'    => $this->input->{'input_asset'}->{'file'},
                'Range'  => 'bytes=0-1024'
            ]);
            $chunk = (string) $obj['Body'];
        }
        
        $this->activityHeartbeat();

        // Determine file type
        file_put_contents($tmpFile, $chunk);
        $mime = trim((new CommandExecuter($this->cpeLogger, $this->logKey))->execute(
            'file -b --mime-type '.escapeshellarg($tmpFile))['out']);
        $type = substr($mime, 0, strpos($mime, '/'));

        if ($this->debug)
            $this->cpeLogger->logOut(
                "DEBUG",
                basename(__FILE__),
                "File meta information gathered. Mime: $mime | Type: $type",
                $this->logKey
        );

        // Load the right transcoder base on input_type
        // Get asset detailed info
        switch ($type)
        {
        case 'audio':
        case 'video':
        case 'image':
        default:
            require_once __DIR__.'/transcoders/VideoTranscoder.php';

            // Initiate transcoder obj
            $videoTranscoder = new VideoTranscoder($this, $task);
            // Get input video information
            $assetInfo = $videoTranscoder->getAssetInfo($this->inputFilePath);

            // Liberate memory
            unset($videoTranscoder);
        }

        if ($mime === 'application/octet-stream' && isset($assetInfo->streams)) {
            // Check all stream types
            foreach ($assetInfo->streams as $stream) {
                if ($stream->codec_type === 'video') {
                    // For a video type, set type to video and break
                    $type = 'video';
                    break;
                } elseif ($stream->codec_type === 'audio') {
                    // For an audio type, set to audio, but don't break
                    // in case there's a video stream later
                    $type = 'audio';
                }
            }
        }
        
        $assetInfo->mime = $mime;
        $assetInfo->type = $type;

        $result['input_asset']     = $this->input->{'input_asset'};
        $result['input_metadata']  = $assetInfo;
        $result['output_assets']   = $this->input->{'output_assets'};
        
        return json_encode($result);
    }
}