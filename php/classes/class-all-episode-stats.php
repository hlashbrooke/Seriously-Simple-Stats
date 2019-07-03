<?php

namespace SeriouslySimpleStats\Classes;

use SeriouslySimplePodcasting\Helpers\Log_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class All_Episode_Stats {

	private $table = '';

	private $dates = array();

	public $total_posts = 0;

	public $total_per_page = 0;

	public $logger = null;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ssp_stats';
		$this->dates = array(
			intval( current_time( 'm' ) )                                             => current_time( 'F' ),
			intval( date( 'm', strtotime( current_time( 'Y-m-d' ) . ' -1 MONTH' ) ) ) => date( 'F', strtotime( current_time( "Y-m-d" ) . '-1 MONTH' ) ),
			intval( date( 'm', strtotime( current_time( 'Y-m-d' ) . ' -2 MONTH' ) ) ) => date( 'F', strtotime( current_time( "Y-m-d" ) . '-2 MONTH' ) ),
		);
		$this->logger = new Log_Helper();
	}

	/**
	 * Render episode stats for the last three months, with sorting
	 *
	 * @return false|string
	 */
	public function render_all_episodes_stats(){
		$all_episodes_stats = $this->get_all_episode_stats();
		$sort_order = $this->get_all_episodes_sort_order();
		$html = '';
		ob_start();
		require_once SSP_STATS_DIR_PATH . 'partials/stats-all-episodes.php';
		$html = ob_get_clean();

		//Only show page links if there's more than 25 episodes in the table
		if ( $this->total_posts >= $this->total_per_page ) {
			if ( isset( $_GET['pagenum'] ) ) {
				$pagenum = intval( $_GET['pagenum'] );
			} else {
				$pagenum = 1;
			}
			$total_pages   = ceil( $this->total_posts / $this->total_per_page );
			$prev_page     = ( $pagenum <= 1 ) ? 1 : $pagenum - 1;
			$order_by      = isset( $_GET['orderby'] ) ? '&orderby=' . sanitize_text_field( $_GET['orderby'] ) : "";
			$order         = isset( $_GET['order'] ) ? '&order=' . sanitize_text_field( $_GET['order'] ) : "";
			$month_num     = isset( $_GET['month_num'] ) ? '&month_num=' . sanitize_text_field( $_GET['month_num'] ) : "";
			$next_page_url = admin_url( "edit.php?post_type=podcast&page=podcast_stats" . $order_by . $month_num . $order . "&pagenum=" . $prev_page . "#last-three-months-container" );
			$next_page     = $pagenum + 1;
			ob_start();
			require_once SSP_STATS_DIR_PATH . 'partials/stats-all-episodes-pagination.php';
			$html .= ob_get_clean();
		}

		return $html;
	}

	/**
	 * Get all episode stats to be rendered in the stats-all-episode partial
	 *
	 * @return array
	 */
	private function get_all_episode_stats(){
		global $wpdb;
		$date_keys = array_keys( $this->dates );

		$order_by = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'episode_name';
		$order    = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 'desc';

		$all_episodes_stats = array();
		$all_episode_count  = 0;
		//$this->start_date = strtotime(current_time('Y-m-d') . ' -2 MONTH');
		$sql         = "SELECT COUNT(id) AS listens, post_id FROM $this->table GROUP BY post_id";
		$results     = $wpdb->get_results( $sql );
		$this->total_posts = count( $results );
		if ( is_array( $results ) ) {
			foreach ( $results as $result ) {
				$total_listens_array = array_combine( $date_keys, array( 0, 0, 0 ) );
				$post                = get_post( intval( $result->post_id ) );
				if ( ! $post ) {
					continue;
				}
				$all_episode_count++;
				$sql             = "SELECT `date` FROM $this->table WHERE `post_id` = '" . $result->post_id . "'";
				$episode_results = $wpdb->get_results( $sql );
				$lifetime_count  = count( $episode_results );
				foreach ( $episode_results as $ref ) {
					//Increase the count of listens per month
					if ( isset( $total_listens_array[ intval( date( 'm', intval( $ref->date ) ) ) ] ) ) {
						++ $total_listens_array[ intval( date( 'm', intval( $ref->date ) ) ) ];
					}
				}

				$episode_stats = array(
					'episode_name'  => $post->post_title,
					'date'          => date( 'm-d-Y', strtotime( $post->post_date ) ),
					'slug'          => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
					'listens'       => $lifetime_count,
				);

				foreach ( $this->dates as $date_key => $date ) {
					$episode_stats[ $date ] = $total_listens_array[ $date_key ];
				}

				$all_episodes_stats[ $all_episode_count ] = $episode_stats;
			}
		}

		$all_episodes_stats = apply_filters('ssp_stats_three_months_all_episodes', $all_episodes_stats);

		$this->logger->log( 'All Episodes Stats', $all_episodes_stats );

		//24 because we're counting an array
		$this->total_per_page = apply_filters( 'ssp_stats_three_months_per_page', 24 );

		if( !empty( $all_episodes_stats ) && is_array( $all_episodes_stats ) ){
			//Sort list by episode name
			foreach( $all_episodes_stats as $listen ){
				$listen_sorting[] = $listen[$order_by];
			}

			//$logger->log( 'Listen Sorting', $listen_sorting );

			if ( 'desc' === $order ) {
				array_multisort( $listen_sorting, SORT_DESC, $all_episodes_stats );
			} else {
				array_multisort( $listen_sorting, SORT_ASC, $all_episodes_stats );
			}

			//$logger->log( 'All Episodes Stats Sorted', $all_episodes_stats );

			if( isset( $_GET['pagenum'] ) ){
				$pagenum = intval( $_GET['pagenum'] );
				if( $pagenum <= 1){
					$current_cursor = 0;
				} else {
					$current_cursor = ($pagenum - 1)* $this->total_per_page;
				}
				$all_episodes_stats = array_slice( $all_episodes_stats, $current_cursor, $this->total_per_page , true );
			} else {
				$all_episodes_stats = array_slice( $all_episodes_stats, 0, $this->total_per_page, true );
			}
		}
		return $all_episodes_stats;
	}

	/**
	 * Checks if stats sorting has been passed, otherwise sets up defaults
	 *
	 * @return array
	 */
	private function get_all_episodes_sort_order() {

		$order_by = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'episode_name';
		$order    = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 'desc';

		$publish_order_url  = admin_url( 'edit.php?post_type=podcast&page=podcast_stats&orderby=date&order=desc#last-three-months-container' );
		$name_order_url     = admin_url( 'edit.php?post_type=podcast&page=podcast_stats&orderby=episode_name&order=desc#last-three-months-container' );
		$lifetime_order_url = admin_url( 'edit.php?post_type=podcast&page=podcast_stats&orderby=listens&order=desc#last-three-months-container' );

		$sorting = array(
			'date'  => array( 'sortable desc', $publish_order_url ),
			'episode_name'     => array( 'sortable desc', $name_order_url ),
			'listens' => array( 'sortable desc', $lifetime_order_url ),
		);
		foreach ( $this->dates as $date_key => $date ) {
			$date_order_url      = admin_url( 'edit.php?post_type=podcast&page=podcast_stats&orderby='.$date.'&order=desc#last-three-months-container' );
			$sorting[ $date ] = array( 'sortable desc', $date_order_url );
		}

		$sort_order = array();
		foreach ( $sorting as $key => $sorting_item ) {
			if ( $key === $order_by ) {
				$sorting_item[0] = 'sorted ' . $order;
				if ( 'desc' === $order ) {
					$sorting_item[1] = str_replace( 'desc', 'asc', $sorting_item[1] );
				}
			}
			$sort_order[ $key ] = $sorting_item;
		}

		return $sort_order;
	}

}
