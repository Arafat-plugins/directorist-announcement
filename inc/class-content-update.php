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

		// Only admin can send announcements
		if ( ! current_user_can( 'manage_options' ) ) {
			$status['message'] = __( 'You are not allowed to send announcement', 'directorist-announcement' );
			wp_send_json( $status );
		}

		// Validate Subject
		if ( empty( $subject ) ) {
			$status['message'] = __( 'The subject cannot be empty', 'directorist-announcement' );
			wp_send_json( $status );
		}

		// Validate Message
		if ( empty( $message ) ) {
			$status['message'] = __( 'The message cannot be empty', 'directorist-announcement' );
			wp_send_json( $status );
		}

		if ( strlen( $message ) > 400 ) {
			$status['message'] = __( 'Maximum 400 characters are allowed for the message', 'directorist-announcement' );
			wp_send_json( $status );
		}
		
		$users_email = ( 'all_user' === $to ) ? DA_Helpers::get_all_user_emails() : explode( ',', $recipient );

		foreach( $users_email as $email ) {
			// Save the post
			$announcement = wp_insert_post(
				array(
					'post_type'    => 'listing-announcement',
					'post_title'   => $subject,
					'post_content' => $message,
					'post_status'  => 'publish',
					'post_author'  => email_exists( $email ),
				)
			);
		}

		if ( is_wp_error( $announcement ) ) {
			$status['message'] = __( 'Sorry, something went wrong, please try again', 'directorist-announcement' );
			wp_send_json( $status );
		}

		$status['announcement'] = $announcement;

		$recipients = array();

		// Get Recipient
		if ( 'selected_user' === $to ) {
			$recipients = explode( ',', $recipient );
			$recipients = array_map( 'trim', $recipients );
			$recipients = array_filter( $recipients, 'is_email' );
			$recipients = array_unique( $recipients );

			// Validate recipient
			if ( empty( $recipients ) ) {
				$status['message'] = __( 'No recipient found', 'directorist-announcement' );
				wp_send_json( $status );
			}
		}

		if ( 'all_user' === $to ) {
			$users = DA_Helpers::get_all_user_emails();

			if ( ! empty( $users ) ) {
				$recipients = $users;
			}

			// Validate recipient
			if ( empty( $recipients ) ) {
				$status['message'] = __( 'No recipient found', 'directorist-announcement' );
				wp_send_json( $status );
			}
		}

		if ( 'all_user' !== $to ) {
			update_post_meta( $announcement, '_recepents', $recipient );
		} else {
			update_post_meta( $announcement, '_recepents', '' );
		}

		// Update the post meta
		update_post_meta( $announcement, '_to', $to );
		update_post_meta( $announcement, '_closed', false );
		update_post_meta( $announcement, '_seen', false );

		if ( empty( $expiration ) ) {
			$expiration = 365;
		}

		$today    = date( 'Y-m-d' );
		$exp_date = date( 'Y-m-d', strtotime( $today . " + {$expiration} days" ) );

		update_post_meta( $announcement, '_exp_in_days', $expiration );
		update_post_meta( $announcement, '_exp_date', $exp_date );

		// Send email if enabled
		if ( $send_to_email ) {
			$message = atbdp_email_html( $subject, $message );
			$headers = ATBDP()->email->get_email_headers();

			ATBDP()->email->send_mail( $recipients, $subject, $message, $headers );
		}

		$status['success'] = true;
		$status['message'] = __( 'The announcement has been sent successfully', 'directorist-announcement' );

		wp_send_json( $status );
	}

	public function non_legacy_add_dashboard_nav_link() {
		$announcement_tab      = get_directorist_option( 'announcement_tab', 'directorist-announcement' );
		$saved_text            = get_directorist_option( 'announcement_tab_text', '' );
		$announcement_tab_text = ( empty( $saved_text ) || $saved_text === 'Announcements' ) ? __( 'Announcements', 'directorist-announcement' ) : $saved_text;
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
