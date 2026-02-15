<?php
/**
 * @author  wpWax
 * @since   1.0
 * @version 1.0
 */

namespace wpWax\DA;

use WP_Query;

if ( class_exists( 'DA_Update' ) ) {
	return;
}

class DA_Update {

	protected static $instance = null;

	public function __construct() {
		 // Cteate announcement post type
		add_action( 'init', array( $this, 'create_announcement_post_type' ) );

		add_action( 'atbdp_schedule_task', array( $this, 'delete_expired_announcements' ) );

		// Handle ajax
		add_action( 'wp_ajax_atbdp_send_announcement', array( $this, 'send_announcement' ) );
		add_action( 'wp_ajax_atbdp_close_announcement', array( $this, 'close_announcement' ) );
		add_action( 'wp_ajax_atbdp_get_new_announcement_count', array( $this, 'response_new_announcement_count' ) );
		add_action( 'wp_ajax_atbdp_clear_seen_announcements', array( $this, 'clear_seen_announcements' ) );
		add_action( 'wp_ajax_directorist_get_announcement_progress', array( $this, 'get_announcement_progress' ) );

		// Background email queue: run via WP Cron so AJAX returns immediately and avoids timeout.
		add_action( 'directorist_process_announcement_queue', array( $this, 'process_announcement_email_queue' ) );
		add_filter( 'cron_schedules', array( $this, 'add_announcement_queue_cron_interval' ) );
		add_action( 'init', array( $this, 'schedule_announcement_queue_cron' ) );
	}

	/**
	 * Add "every minute" interval for announcement email queue cron.
	 */
	public function add_announcement_queue_cron_interval( $schedules ) {
		$schedules['directorist_announcement_every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (Directorist announcement queue)', 'directorist-announcement' ),
		);
		return $schedules;
	}

	/**
	 * Schedule cron event if not already scheduled (runs every minute).
	 */
	public function schedule_announcement_queue_cron() {
		if ( wp_next_scheduled( 'directorist_process_announcement_queue' ) ) {
			return;
		}
		wp_schedule_event( time(), 'directorist_announcement_every_minute', 'directorist_process_announcement_queue' );
	}

	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	// create_announcement_post_type
	public function create_announcement_post_type() {
		register_post_type(
			'listing-announcement',
			array(
				'label'  => 'Announcement',
				'labels' => 'Announcements',
				'public' => false,
			)
		);
	}

	// delete_expired_announcements
	public function delete_expired_announcements() {
		$expaired_announcements = new WP_Query(
			array(
				'post_type'      => 'listing-announcement',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => '_exp_date',
						'value'   => date( 'Y-m-d' ),
						'compare' => '<=',
					),
				),
			)
		);

		if ( ! $expaired_announcements->have_posts() ) {
			return;
		}
		while ( $expaired_announcements->have_posts() ) {
			$expaired_announcements->the_post();
			wp_delete_post( get_the_ID(), true );
		}
		wp_reset_postdata();
	}

	// send_announcement
	public function send_announcement() {

		$nonce         = isset($_POST['nonce']) ? wp_unslash($_POST['nonce']) : ''; // @codingStandardsIgnoreLine.WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$to            = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : 'all_user';
		$recipient     = isset( $_POST['recipient'] ) ? sanitize_text_field( wp_unslash( $_POST['recipient'] ) ) : '';
		$subject       = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$message       = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$expiration    = isset( $_POST['expiration'] ) ? intval( $_POST['expiration'] ) : 0;
		$send_to_email = isset( $_POST['send_to_email'] ) ? boolval( $_POST['send_to_email'] ) : true;

		$status = array(
			'success' => false,
			'message' => __( 'Sorry, something went wrong, please try again', 'directorist-announcement' ),
		);

		if ( ! wp_verify_nonce( $nonce, directorist_get_nonce_key() ) ) {
			$status['message'] = __( 'Invalid request', 'directorist-announcement' );
			wp_send_json( $status );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$status['message'] = __( 'You are not allowed to send announcement', 'directorist-announcement' );
			wp_send_json( $status );
		}

		if ( empty( $subject ) ) {
			$status['message'] = __( 'The subject cannot be empty', 'directorist-announcement' );
			wp_send_json( $status );
		}

		if ( empty( $message ) ) {
			$status['message'] = __( 'The message cannot be empty', 'directorist-announcement' );
			wp_send_json( $status );
		}

		if ( strlen( $message ) > 400 ) {
			$status['message'] = __( 'Maximum 400 characters are allowed for the message', 'directorist-announcement' );
			wp_send_json( $status );
		}

		// --- 1. Fetch all recipient emails (same as before, using existing helper) ---
		$raw_emails = ( 'all_user' === $to ) ? DA_Helpers::get_all_user_emails() : array_map( 'trim', explode( ',', $recipient ) );

		// --- 2. Filter invalid emails and ensure each has a valid user ID (avoids invalid post_author) ---
		$valid_recipients = array();
		foreach ( (array) $raw_emails as $email ) {
			$email = is_string( $email ) ? trim( $email ) : $email;
			if ( ! is_email( $email ) ) {
				continue;
			}
			$user_id = email_exists( $email );
			// Only include recipients that exist as users so post_author is always valid.
			if ( $user_id ) {
				$valid_recipients[ $email ] = $user_id;
			}
		}
		// Deduplicate by email key only; array_unique(..., SORT_REGULAR) is unreliable on associative arrays.
		$valid_recipients = array_intersect_key( $valid_recipients, array_flip( array_unique( array_keys( $valid_recipients ) ) ) );

		if ( empty( $valid_recipients ) ) {
			$status['message'] = __( 'No recipient found', 'directorist-announcement' );
			wp_send_json( $status );
		}

		// --- 3. Insert one announcement post per valid user; store all created IDs ---
		$created_post_ids = array();
		foreach ( $valid_recipients as $email => $user_id ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'listing-announcement',
					'post_title'   => $subject,
					'post_content' => $message,
					'post_status'  => 'publish',
					'post_author'  => $user_id,
				)
			);
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}
			$created_post_ids[] = $post_id;
		}

		if ( empty( $created_post_ids ) ) {
			$status['message'] = __( 'Sorry, something went wrong, please try again', 'directorist-announcement' );
			wp_send_json( $status );
		}

		// --- 4. Compute expiration date once (reused for all posts to reduce memory) ---
		if ( empty( $expiration ) ) {
			$expiration = 365;
		}
		$today    = date( 'Y-m-d' );
		$exp_date = date( 'Y-m-d', strtotime( $today . " + {$expiration} days" ) );

		// --- 5. Update metadata on every created post (not just the last one) ---
		// Plugin expects _recepents as array for recipient filtering; empty array for "all_user".
		$recepents_value = ( 'all_user' === $to ) ? array() : array_keys( $valid_recipients );
		foreach ( $created_post_ids as $post_id ) {
			update_post_meta( $post_id, '_recepents', $recepents_value );
			update_post_meta( $post_id, '_to', $to );
			update_post_meta( $post_id, '_closed', false );
			update_post_meta( $post_id, '_seen', false );
			update_post_meta( $post_id, '_exp_in_days', $expiration );
			update_post_meta( $post_id, '_exp_date', $exp_date );
		}

		// --- 6. Queue emails for background sending (prevents AJAX timeout when user count is large) ---
		$valid_emails = array_keys( $valid_recipients );
		if ( $send_to_email && ! empty( $valid_emails ) ) {
			$html_message = atbdp_email_html( $subject, $message );
			$queue_key    = 'directorist_announcement_email_queue';
			// Create option with autoload=no so a large queue does not slow every page load.
			if ( get_option( $queue_key, null ) === null ) {
				add_option( $queue_key, array(), '', 'no' );
			}
			$queue   = get_option( $queue_key, array() );
			// Queue entry: recipients, subject, message, total_count (fixed), sent_count (updated each batch).
			$queue[] = array(
				'recipients'   => $valid_emails,
				'subject'     => $subject,
				'message'     => $html_message,
				'total_count' => count( $valid_emails ),
				'sent_count'  => 0,
			);
			update_option( $queue_key, $queue );
			// Trigger one batch immediately so low-traffic sites still process queue without waiting for cron.
			do_action( 'directorist_process_announcement_queue' );
		}

		// --- 7. Return success immediately; emails are sent by WP Cron in the background ---
		$status['success']      = true;
		$status['message']     = __( 'The announcement has been sent successfully', 'directorist-announcement' );
		$status['announcement'] = $created_post_ids;

		wp_send_json( $status );
	}

	/**
	 * Process announcement email queue in small batches (WP Cron handler).
	 * Sending in the background prevents the AJAX request from timing out when there are many recipients.
	 * Batching: we send a fixed number of emails per run (default 25), then remove them from the queue,
	 * so each cron run stays within time/memory limits and the queue drains over multiple runs until empty.
	 */
	public function process_announcement_email_queue() {
		$lock_key = 'directorist_announcement_queue_lock';
		// Prevent concurrent runs (cron + do_action + page load) from sending duplicates.
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 60 );

		$queue_key = 'directorist_announcement_email_queue';
		$queue     = get_option( $queue_key, array() );
		if ( empty( $queue ) || ! is_array( $queue ) ) {
			delete_transient( $lock_key );
			return;
		}
		if ( ! function_exists( 'ATBDP' ) || ! ATBDP()->email ) {
			delete_transient( $lock_key );
			return;
		}

		$batch_size = (int) apply_filters( 'directorist_announcement_email_batch_size', 25 );
		$batch_size = $batch_size > 0 ? $batch_size : 25;

		$head      = array_shift( $queue );
		$recipients = isset( $head['recipients'] ) && is_array( $head['recipients'] ) ? $head['recipients'] : array();
		$subject    = isset( $head['subject'] ) ? $head['subject'] : '';
		$message    = isset( $head['message'] ) ? $head['message'] : '';
		// Progress: total_count never changes; sent_count increases by batch size each run.
		$total_count = isset( $head['total_count'] ) ? (int) $head['total_count'] : count( $recipients );
		$sent_count  = isset( $head['sent_count'] ) ? (int) $head['sent_count'] : 0;

		if ( empty( $recipients ) ) {
			update_option( $queue_key, $queue );
			delete_transient( $lock_key );
			return;
		}

		// Send one batch (default 25); remaining recipients stay in queue for next run.
		$to_send   = array_slice( $recipients, 0, $batch_size );
		$remaining = array_slice( $recipients, $batch_size );

		$headers = ATBDP()->email->get_email_headers();
		$sent = ATBDP()->email->send_mail( $to_send, $subject, $message, $headers );
		if ( $sent === false ) {
			error_log( '[Directorist Announcement] Email batch failed' );
		}

		$sent_count += count( $to_send );

		if ( ! empty( $remaining ) ) {
			array_unshift( $queue, array(
				'recipients'   => $remaining,
				'subject'      => $subject,
				'message'      => $message,
				'total_count'  => $total_count,
				'sent_count'   => $sent_count,
			) );
		}
		update_option( $queue_key, $queue );
		// Release lock so next cron or trigger can run.
		delete_transient( $lock_key );
	}

	/**
	 * AJAX: return progress for the first queue entry (total, sent, remaining, percent).
	 * Progress is derived from total_count (fixed per entry) and sent_count (updated each batch).
	 */
	public function get_announcement_progress() {
		$queue_key = 'directorist_announcement_email_queue';
		$queue     = get_option( $queue_key, array() );

		if ( empty( $queue ) || ! is_array( $queue ) ) {
			wp_send_json( array( 'total' => 0, 'sent' => 0, 'remaining' => 0, 'percent' => 100 ) );
		}

		// Safe first entry: reset() works with non-sequential keys; false when empty.
		$first = reset( $queue );
		if ( $first === false ) {
			wp_send_json( array( 'total' => 0, 'sent' => 0, 'remaining' => 0, 'percent' => 100 ) );
		}

		$total      = isset( $first['total_count'] ) ? (int) $first['total_count'] : 0;
		$sent       = isset( $first['sent_count'] ) ? (int) $first['sent_count'] : 0;
		$recipients = isset( $first['recipients'] ) && is_array( $first['recipients'] ) ? $first['recipients'] : array();
		$remaining  = count( $recipients );

		if ( $total <= 0 ) {
			wp_send_json( array( 'total' => 0, 'sent' => 0, 'remaining' => 0, 'percent' => 100 ) );
		}

		// Clamp percent so sent_count > total_count never yields > 100.
		$percent = $total > 0 ? min( 100, (int) round( ( $sent / $total ) * 100 ) ) : 100;
		wp_send_json( array( 'total' => $total, 'sent' => $sent, 'remaining' => $remaining, 'percent' => $percent ) );
	}

	public function non_legacy_add_dashboard_nav_link() {
		$announcement_tab      = get_directorist_option( 'announcement_tab', 'directorist-announcement' );
		$announcement_tab_text = get_directorist_option( 'announcement_tab_text', __( 'Announcements', 'directorist-announcement' ) );
		if ( empty( $announcement_tab ) ) {
			return;
		}
		$nav_label         = $announcement_tab_text . " <span class='atbdp-nav-badge new-announcement-count'></span>";
		$new_announcements = DA_Helpers::get_new_announcement_count();

		if ( $new_announcements > 0 ) {
			$nav_label = $announcement_tab_text . " <span class='atbdp-nav-badge new-announcement-count show'>{$new_announcements}</span>";
		} ?>

		<li class="directorist-tab__nav__item">
			<a href="#" class="directorist-booking-nav-link directorist-tab__nav__link" target="announcement">
				<span class="directorist_menuItem-text">
					<span class="directorist_menuItem-icon"><?php directorist_icon( 'las la-bullhorn' ); ?></span><?php echo wp_kses( $nav_label, array( 'span' => array( 'class' => array() ) ) ); ?>
				</span>
			</a>
		</li>

		<?php
	}

	// close_announcement
	public function close_announcement() {
		$status = array( 'success' => false );

		if ( ! directorist_verify_nonce( 'nonce' ) ) {
			$status['message'] = __( 'Sorry, something went wrong, please try again', 'directorist-announcement' );
			wp_send_json( $status );
		}

		$post_id = ( isset( $_POST['post_id'] ) ) ? absint( $_POST['post_id'] ) : 0;

		// Validate post id
		if ( empty( $post_id ) ) {
			$status['message'] = __( 'Sorry, something went wrong, please try again', 'directorist-announcement' );
			wp_send_json( $status );
		}

		update_post_meta( $post_id, '_closed', true );

		$status['success'] = true;
		$status['message'] = __( 'The announcement has been closed successfully', 'directorist-announcement' );

		wp_send_json( $status );
	}

	// response_new_announcement_count
	public function response_new_announcement_count() {
		 $new_announcements = DA_Helpers::get_new_announcement_count();
		wp_send_json(
			array(
				'success'                => true,
				'total_new_announcement' => $new_announcements,
			)
		);
	}

	// clear_seen_announcements
	public function clear_seen_announcements() {
		$new_announcements = new WP_Query(
			array(
				'post_type'      => 'listing-announcement',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => '_exp_date',
						'value'   => date( 'Y-m-d' ),
						'compare' => '>',
					),
					array(
						'key'     => '_closed',
						'value'   => '1',
						'compare' => '!=',
					),
					array(
						'key'     => '_seen',
						'value'   => '1',
						'compare' => '!=',
					),
				),
			)
		);

		$current_user_email = get_the_author_meta( 'user_email', get_current_user_id() );

		if ( $new_announcements->have_posts() ) {
			while ( $new_announcements->have_posts() ) {
				$new_announcements->the_post();
				// Check recepent restriction
				$recipient = get_post_meta( get_the_ID(), '_recepents', true );
				if ( ! empty( $recipient ) && is_array( $recipient ) ) {
					if ( ! in_array( $current_user_email, $recipient ) ) {
						continue;
					}
				}

				update_post_meta( get_the_ID(), '_seen', true );
			}
			wp_reset_postdata();
		}

		wp_send_json( array( 'success' => true ) );
	}

}

DA_Update::instance();
