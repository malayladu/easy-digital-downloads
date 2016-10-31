<?php
/**
 * Earnings / Sales Stats
 *
 * @package     EDD
 * @subpackage  Classes/Stats
 * @copyright   Copyright (c) 2015, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.8
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EDD_Stats Class
 *
 * This class is for retrieving stats for earnings and sales
 *
 * Stats can be retrieved for date ranges and pre-defined periods
 *
 * @since 1.8
 */
class EDD_Payment_Stats extends EDD_Stats {


	/**
	 * Retrieve sale stats
	 *
	 * @access public
	 * @since 1.8
	 * @param INT $download_id The download product to retrieve stats for. If false, gets stats for all products
	 * @param string|bool $start_date The starting date for which we'd like to filter our sale stats. If false, we'll use the default start date of `this_month`
	 * @param string|bool $end_date The end date for which we'd like to filter our sale stats. If false, we'll use the default end date of `this_month`
	 * @param string|array $status The sale status(es) to count. Only valid when retrieving global stats
	 * @return float|int Total amount of sales based on the passed arguments.
	 */
	public function get_sales( $download_id = 0, $start_date = false, $end_date = false, $status = 'publish' ) {

		$this->setup_dates( $start_date, $end_date );

		// Make sure start date is valid
		if( is_wp_error( $this->start_date ) )
			return $this->start_date;

		// Make sure end date is valid
		if( is_wp_error( $this->end_date ) )
			return $this->end_date;

		if( empty( $download_id ) ) {

			// Global sale stats
			add_filter( 'edd_count_payments_where', array( $this, 'count_where' ) );

			if( is_array( $status ) ) {
				$count = 0;
				foreach( $status as $payment_status ) {
					$count += edd_count_payments()->$payment_status;
				}
			} else {
				$count = edd_count_payments()->$status;
			}

			remove_filter( 'edd_count_payments_where', array( $this, 'count_where' ) );

		} else {

			$this->timestamp = false;

			// Product specific stats
			global $edd_logs;

			add_filter( 'posts_where', array( $this, 'payments_where' ) );

			$count = $edd_logs->get_log_count( $download_id, 'sale' );

			remove_filter( 'posts_where', array( $this, 'payments_where' ) );

		}

		return $count;

	}


	/**
	 * Retrieve earning stats
	 *
	 * @access public
	 * @since 1.8
	 * @param INT $download_id The download product to retrieve stats for. If false, gets stats for all products
	 * @param string|bool $start_date The starting date for which we'd like to filter our sale stats. If false, we'll use the default start date of `this_month`
	 * @param string|bool $end_date The end date for which we'd like to filter our sale stats. If false, we'll use the default end date of `this_month`
	 * @param bool $include_taxes If taxes should be included in the earnings graphs
	 * @return float|int Total amount of sales based on the passed arguments.
	 */
	public function get_earnings( $download_id = 0, $start_date = false, $end_date = false, $include_taxes = true ) {

		global $wpdb;
		$this->setup_dates( $start_date, $end_date );

		// Make sure start date is valid
		if( is_wp_error( $this->start_date ) )
			return $this->start_date;

		// Make sure end date is valid
		if( is_wp_error( $this->end_date ) )
			return $this->end_date;

		add_filter( 'posts_where', array( $this, 'payments_where' ) );

		if ( empty( $download_id ) ) {

			// Global earning stats

			$args = array(
				'post_type'              => 'edd_payment',
				'nopaging'               => true,
				'post_status'            => array( 'publish', 'revoked' ),
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
				'start_date'             => $this->start_date, // These dates are not valid query args, but they are used for cache keys
				'end_date'               => $this->end_date,
				'edd_transient_type'     => 'edd_earnings', // This is not a valid query arg, but is used for cache keying
				'include_taxes'          => $include_taxes,
			);

			$args = apply_filters( 'edd_stats_earnings_args', $args );
			$cached   = get_transient( 'edd_stats_earnings' );
			$key      = md5( json_encode( $args ) );

			if ( ! isset( $cached[ $key ] ) ) {
				$sales    = get_posts( $args );
				$earnings = 0;

				if ( $sales ) {

					$sales = implode( ',', array_map('intval', $sales ) );

					$total_earnings = $wpdb->get_var( "SELECT SUM(meta_value) FROM $wpdb->postmeta WHERE meta_key = '_edd_payment_total' AND post_id IN ({$sales})" );
					$total_tax      = 0;

					if ( ! $include_taxes ) {
						$total_tax = $wpdb->get_var( "SELECT SUM(meta_value) FROM $wpdb->postmeta WHERE meta_key = '_edd_payment_tax' AND post_id IN ({$sales})" );
					}

					$total_earnings = apply_filters( 'edd_payment_stats_earnings_total', $total_earnings, $sales, $args );

					$earnings += ( $total_earnings - $total_tax );

				}
				// Cache the results for one hour
				$cached[ $key ] = $earnings;
				set_transient( 'edd_stats_earnings', $cached, HOUR_IN_SECONDS );
			}

		} else {

			// Download specific earning stats
			global $edd_logs, $wpdb;

			$args = array(
				'post_parent'        => $download_id,
				'nopaging'           => true,
				'log_type'           => 'sale',
				'fields'             => 'ids',
				'suppress_filters'   => false,
				'start_date'         => $this->start_date, // These dates are not valid query args, but they are used for cache keys
				'end_date'           => $this->end_date,
				'edd_transient_type' => 'edd_earnings', // This is not a valid query arg, but is used for cache keying
				'include_taxes'      => $include_taxes,
			);

			$args     = apply_filters( 'edd_stats_earnings_args', $args );
			$cached   = get_transient( 'edd_stats_earnings' );
			$key      = md5( json_encode( $args ) );

			if ( ! isset( $cached[ $key ] ) ) {
				$this->timestamp = false;
				$log_ids  = $edd_logs->get_connected_logs( $args, 'sale' );

				$earnings = 0;

				if( $log_ids ) {
					$log_ids     = implode( ',', array_map('intval', $log_ids ) );
					$payment_ids = $wpdb->get_col( "SELECT DISTINCT meta_value FROM $wpdb->postmeta WHERE meta_key = '_edd_log_payment_id' AND post_id IN ($log_ids);" );

					foreach( $payment_ids as $payment_id ) {
						$items = edd_get_payment_meta_cart_details( $payment_id );

						foreach( $items as $cart_key => $item ) {

							if( $item['id'] != $download_id ) {
								continue;
							}

							$earnings += $item['price'];

							// Check if there are any item specific fees
							if ( ! empty( $item['fees'] ) ) {
								foreach ( $item['fees'] as $key => $fee ) {
									$earnings += $fee['amount'];
								}
							}

							$earnings = apply_filters( 'edd_payment_stats_item_earnings', $earnings, $payment_id, $cart_key, $item );

							if ( ! $include_taxes ) {
								$earnings -= edd_get_payment_item_tax( $payment_id, $cart_key );
							}

						}

					}
				}

				// Cache the results for one hour
				$cached[ $key ] = $earnings;
				set_transient( 'edd_stats_earnings', $cached, HOUR_IN_SECONDS );
			}
		}

		remove_filter( 'posts_where', array( $this, 'payments_where' ) );

		$result = $cached[ $key ];

		return round( $result, edd_currency_decimal_filter() );

	}

	/**
	 * Get the best selling products
	 *
	 * @access public
	 * @since 1.8
	 * @param int $number The number of results to retrieve with the default set to 10.
	 * @return array List of download IDs that are best selling
	 */
	public function get_best_selling( $number = 10 ) {

		global $wpdb;

		$downloads = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id as download_id, max(meta_value) as sales
				FROM $wpdb->postmeta WHERE meta_key='_edd_download_sales' AND meta_value > 0
				GROUP BY meta_value+0
				DESC LIMIT %d;", $number
		) );

		return $downloads;
	}

	/**
	 * Retrieve sales stats on a hourly basis
	 *
	 * @access public
	 * @since  2.6.11
	 *
	 * @param INT $download_id The download product to retrieve stats for. If false, gets stats for all products
	 * @param string|bool $start_date The starting date for which we'd like to filter our sale stats. If false, we'll use the default start date of `this_month`
	 * @param string|bool $end_date The end date for which we'd like to filter our sale stats. If false, we'll use the default end date of `this_month`
	 * @param string|array $status The sale status(es) to count. Only valid when retrieving global stats
	 * @return array Total amount of sales based on the passed arguments.
	 */
	public function get_hourly_sales( $download_id = 0, $start_date = false, $end_date = false, $status = 'publish' ) {
		global $wpdb;

		$this->setup_dates( $start_date, $end_date );

		// Make sure start date is valid
		if ( is_wp_error( $this->start_date ) ) {
			return $this->start_date;
		}

		// Make sure end date is valid
		if ( is_wp_error( $this->end_date ) ) {
			return $this->end_date;
		}

		$sales = $wpdb->get_results( $wpdb->prepare(
			"SELECT HOUR(posts.post_date) AS h, COUNT(*) as count
			 FROM {$wpdb->posts} AS posts
			 WHERE posts.post_type IN ('edd_payment')
			 AND posts.post_status IN (%s)
			 AND posts.post_date >= %s
			 AND posts.post_date < %s
			 AND ((posts.post_status = 'publish' OR posts.post_status = 'revoked' OR posts.post_status = 'cancelled' OR posts.post_status = 'edd_subscription'))
			 GROUP BY HOUR(posts.post_date)
			 ORDER by posts.post_date ASC", $status, date( 'Y-m-d', $this->start_date ), date( 'Y-m-d', strtotime( '+1 DAY', $this->end_date ) ) ), ARRAY_A );

		return $sales;
	}

}
