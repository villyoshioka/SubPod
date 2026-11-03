<?php
/**
 * バックアップと復元の全体の進み具合を計算する
 *
 * 工程ごとに重みを決めて、工程の中は処理した量(バイト数やテーブル数)で進める。
 *
 * @package SubPod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SP_Progress {
	/** @var callable|null fn( string $message, int $percent ): void */
	private $report;

	/** @var callable|null 長い処理の途中で定期的に呼ぶ */
	private $heartbeat;

	private int $total_weight;
	private int $finished_weight = 0;
	private int $current_weight = 0;
	private int $total = 0;
	private int $count = 0;
	private string $message = '';
	private int $last_percent = -1;

	/**
	 * @param array<string, int> $stages 工程の名前 => 重み
	 */
	public function __construct( ?callable $report, ?callable $heartbeat, private readonly array $stages ) {
		$this->report       = $report;
		$this->heartbeat    = $heartbeat;
		$this->total_weight = max( 1, array_sum( $stages ) );
	}

	/**
	 * 次の工程を始める
	 *
	 * @param int $total この工程で処理する量。分からなければ 0 で、工程の間は同じ値のまま
	 */
	public function begin( string $stage, string $message, int $total = 0 ): void {
		$this->finished_weight += $this->current_weight;
		$this->current_weight   = $this->stages[ $stage ] ?? 0;
		$this->total            = $total;
		$this->count            = 0;
		$this->message          = $message;
		$this->last_percent     = -1;
		$this->send();
	}

	public function tick( int $amount = 0 ): void {
		$this->count += $amount;
		$this->send();
		if ( $this->heartbeat ) {
			( $this->heartbeat )();
		}
	}

	private function send(): void {
		$percent = $this->percent();
		if ( $this->report && $percent !== $this->last_percent ) {
			$this->last_percent = $percent;
			( $this->report )( $this->message, $percent );
		}
	}

	private function percent(): int {
		$ratio = $this->total > 0 ? min( 1, $this->count / $this->total ) : 0;
		return (int) floor( ( $this->finished_weight + $this->current_weight * $ratio ) / $this->total_weight * 100 );
	}
}
