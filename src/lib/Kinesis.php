<?php
namespace Leo\lib;
use Aws\Kinesis\KinesisClient;

class Kinesis extends Uploader{
	private $client;
	private $stream;
	private $id;


	// public $batch_size = 1024 * 1024 * 1;
	public $batch_size = 1048576;
	// public $record_size = 1024 * 1024 * 1;
	public $record_size = 1048576;
	public $max_records = 500;
	public $duration = 10;

	public $bytesPerSecond = 1024 * 1024 * 1.5;

	public $combine = true;

	private $opts;

	public function __construct($id, $config, $opts=[]) {
		$this->id = $id;
		$this->opts = array_merge([
			"maxRetries"=>4,
			'profile' => 'default',
		], $opts);
		$this->stream = $config['leosdk']['kinesis'];
		$this->client = new KinesisClient(array(
			'profile' => $config['leoaws']['profile'],
			"region"=> $config['leoaws']['region'],
			"version"=>"2013-12-02",
			'http'    => [
				'verify' => false
			]
		));
	}

	public function sendRecords($batch) {
		$retries = 0;
		$correlation = null;
		do {
			$time_start = microtime(true);
			$cnt = 0;
			$len =0;
			foreach($batch['records'] as $record) {
				$cnt += $record['cnt'];
				$len += $record['length'];
			}
			Utils::log($this->id);
			$result = $this->client->putRecords([
				'StreamName' => $this->stream,
				'Records' => array_map(function($record) {
					return [
						"Data"=>gzencode($record['data']),
						"PartitionKey"=> $this->id

					];
				},$batch['records'])
			]);

			if($retries > 0) {
				Utils::log("Retrying(#{$retries}) {$cnt} records of size ({$len}) in " . (microtime(true) - $time_start) . " seconds");
			} else {
				Utils::log("Sent {$cnt} records of size ({$len}) in " . (microtime(true) - $time_start) . " seconds");
			}
			$hasErrors = $result->get('FailedRecordCount') == 0;
			if(!$hasErrors) {
				$batch['records'] = [];
			} else { //we need to prune the ones that went through
				$responses = $result->get("Records");
				$maxCompleted = -1;
				foreach($responses as $i=>$response) {
					if(isset($response['SequenceNumber'])) {
						if($maxCompleted == $i -1) { //Was the last one completed, then this one can be moved
							$maxCompleted = $i;
							$correlation = $batch['records'][$maxCompleted]['correlation'];
						}
						unset($batch['records'][$i]);
					}
				}
			}
			$retries++;
		} while (\count($batch['records']) > 0 && $retries < $this->opts['maxRetries']);

		if(\count($batch['records']) > 0) {
			return [
				"success"=>false,
				'errorMessage' => 'Failed to write ' . count($batch['records']) . ' events to the stream',
			];
		} else {
			if(!empty($correlation['end'])) {
				$checkpoint = $correlation['end'];
			} else {
				$checkpoint = $correlation['start'];
			}
			return [
				"success"=>true,
				"eid"=>$checkpoint,
				"records"=>$cnt
			];
		}
	}


	public function end() {
		
	}
}