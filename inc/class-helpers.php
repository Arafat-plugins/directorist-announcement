<?php
/**
 * @author  wpWax
 * @since   1.0
 * @version 1.0
 */

namespace wpWax\DA;

use WP_Query;
use WP_User_Query;

class DA_Helpers {


	public static function get_announcement_label() {
		$saved              = get_directorist_option( 'announcement_tab_text', '' );
		$announcement_label = ( '' === $saved || 'Announcements' === $saved ) ? __( 'Announcements', 'directorist-announcement' ) : $saved;
		$new_announcements  = self::get_new_announcement_count();
		if ( $new_announcements > 0 ) {
			$announcement_label = $announcement_label . "<span class='directorist-announcement-count show'>{$new_announcements}</span>";
		}
		return apply_filters( 'directorist_announcement_label', $announcement_label );
	}

	public static function get_announcements() {
		$announcements       = array();
		$announcements_query = self::get_announcement_query_data();
		$current_user_email  = get_the_author_meta( 'user_email', get_current_user_id() );

		foreach ( $announcements_query->posts as $announcement ) {

			$id = $announcement->ID;
			$recepents = get_post_meta( $id, '_recepents', true );
			$recepents = ! empty( $recepents ) ? explode( ',', $recepents ) : [];

			if ( ! empty( $recepents ) && is_array( $recepents )  ) {
				if ( ! in_array( $current_user_email, $recepents ) ) {
					continue;
				}
			}

			$announcements[ $id ] = array(
				'title'   => get_the_title( $id ),
				'content' => $announcement->post_content,
			);
		}

		return $announcements;
	}

	// get_announcement_querys
	public static function get_announcement_query_data() {
		$announcements = new WP_Query(
			array(
				'post_type'      => 'listing-announcement',
				'posts_per_page' => 20,
				'post_author'    => get_current_user_id(),
				'meta_query'     => array(
					'relation' => 'AND',
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
				),
			)
		);

		return $announcements;
	}

	// get_new_announcement_count
	public static function get_new_announcement_count() {
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

		$total_posts        = count( $new_announcements->posts );
		$skipped_post_count = 0;
		$current_user_email = get_the_author_meta( 'user_email', get_current_user_id() );

		if ( $new_announcements->have_posts() ) {
			while ( $new_announcements->have_posts() ) {
				$new_announcements->the_post();
				// Check recepent restriction
				$recipient = get_post_meta( get_the_ID(), '_recepents', true );
				if ( ! empty( $recipient ) && is_array( $recipient ) ) {
					if ( ! in_array( $current_user_email, $recipient ) ) {
						$skipped_post_count++;
						continue;
					}
				}
			}
			wp_reset_postdata();
		}

		$new_posts = $total_posts - $skipped_post_count;

		return $new_posts;
	}

	public static function non_legacy_add_dashboard_nav_content() {
		 $announcements = self::get_announcement_query_data();

		// directorist_console_log([
		// 'announcements' => $announcements->posts,
		// 'post_type_exists' => post_type_exists( 'listing-announcement' ),
		// ]);

		$total_posts        = count( $announcements->posts );
		$skipped_post_count = 0;
		$current_user_email = get_the_author_meta( 'user_email', get_current_user_id() );

		?>
		<div class="directorist-tab__pane" id="announcement">
			<div class="atbd_announcement_wrapper">
				<?php if ( $announcements->have_posts() ) : ?>
					<div class="atbdp-accordion">
						<?php
						while ( $announcements->have_posts() ) :
							$announcements->the_post();

							// Check recepent restriction
							$recipient = get_post_meta( get_the_ID(), '_recepents', true );
							if ( ! empty( $recipient ) && is_array( $recipient ) ) {
								if ( ! in_array( $current_user_email, $recipient ) ) {
									$skipped_post_count++;
									continue;
								}
							}
							?>
							<div class="atbdp-announcement <?php echo 'update-announcement-status announcement-item announcement-id-' . get_the_ID(); ?>" data-post-id="<?php the_id(); ?>">
								<div class="atbdp-announcement__date">
									<span class="atbdp-date-card-part-1"><?php echo get_the_date( 'd' ); ?></span>
									<span class="atbdp-date-card-part-2"><?php echo get_the_date( 'M' ); ?></span>
									<span class="atbdp-date-card-part-3"><?php echo get_the_date( 'Y' ); ?></span>
								</div>
								<div class="atbdp-announcement__content">
									<h3 class="atbdp-announcement__title">
										<?php the_title(); ?>
									</h3>
									<p><?php the_content(); ?></p>
								</div>
								<div class="atbdp-announcement__close">
									<button class="close-announcement" data-post-id="<?php the_id(); ?>"><i class="la la-times"></i></button>
								</div>
							</div>
							<?php
						endwhile;
						wp_reset_postdata();
						?>
					</div>
				<?php else : ?>
					<div class="directorist_not-found">
						<p><?php esc_html_e( 'No announcement found', 'directorist-announcement' ); ?></p>
					</div>
					<?php
				endif;

				if ( $total_posts && $skipped_post_count == $total_posts ) {
					esc_html_e( 'No announcement found', 'directorist-announcement' );
				}
				?>
			</div>
		</div>
		<?php
	}


	// add_dashboard_nav_link
	public static function add_dashboard_nav_link() {
		$announcement_tab      = get_directorist_option( 'announcement_tab', 'directorist-announcement' );
		$saved_text            = get_directorist_option( 'announcement_tab_text', '' );
		$announcement_tab_text = ( '' === $saved_text || 'Announcements' === $saved_text ) ? __( 'Announcements', 'directorist-announcement' ) : $saved_text;
		if ( empty( $announcement_tab ) ) {
			return;
		}
		$nav_label         = $announcement_tab_text . " <span class='atbdp-nav-badge new-announcement-count'></span>";
		$new_announcements = self::get_new_announcement_count();

		if ( $new_announcements > 0 ) {
			$nav_label = $announcement_tab_text . " <span class='atbdp-nav-badge new-announcement-count show'>{$new_announcements}</span>";
		}
		?>
		<li class="atbdp_tab_nav--content-link">
			<a href="" class="atbdp_all_booking_nav-link atbd-dash-nav-dropdown atbd_tn_link" target="announcement">
				<span class="directorist_menuItem-text">
					<span class="directorist_menuItem-icon"><?php directorist_icon( 'las la-bullhorn' ); ?></span><?php echo wp_kses( $nav_label, array( 'span' => array( 'class' => array() ) ) ); ?>
				</span>
			</a>
		</li>
		<?php
	}

	public static function add_dashboard_nav_content() {
		$announcements      = self::get_announcement_query_data();
		$total_posts        = count( $announcements->posts );
		$skipped_post_count = 0;
		$current_user_email = get_the_author_meta( 'user_email', get_current_user_id() );
		//e_var_dump( $announcements );
		?>
		<div class="atbd_tab_inner" id="announcement">
			<div class="atbd_announcement_wrapper">
				<?php if ( $announcements->have_posts() ) : ?>
					<div class="atbdp-accordion">
						<?php
						while ( $announcements->have_posts() ) :
							$announcements->the_post();

							// Check recepent restriction
							$recipient = get_post_meta( get_the_ID(), '_recepents', true );
							if ( ! empty( $recipient ) && is_array( $recipient ) ) {
								if ( ! in_array( $current_user_email, $recipient ) ) {
									$skipped_post_count++;
									continue;
								}
							}
							?>
							<div class="atbdp-announcement <?php echo 'update-announcement-status announcement-item announcement-id-' . get_the_ID(); ?>" data-post-id="<?php the_id(); ?>">
								<div class="atbdp-announcement__date">
									<span class="atbdp-date-card-part-1"><?php echo get_the_date( 'd' ); ?></span>
									<span class="atbdp-date-card-part-2"><?php echo get_the_date( 'M' ); ?></span>
									<span class="atbdp-date-card-part-3"><?php echo get_the_date( 'Y' ); ?></span>
								</div>
								<div class="atbdp-announcement__content">
									<h3 class="atbdp-announcement__title">
										<?php the_title(); ?>
									</h3>
									<p><?php the_content(); ?></p>
								</div>
								<div class="atbdp-announcement__close">
									<button class="close-announcement" data-post-id="<?php the_id(); ?>"><i class="la la-times"></i></button>
								</div>
							</div>
							<?php
						endwhile;
						wp_reset_postdata();
						?>
					</div>
				<?php else : ?>
					<div class="directorist_not-found">
						<p><?php esc_html_e( 'No announcement found', 'directorist-announcement' ); ?></p>
					</div>
					<?php
				endif;

				if ( $total_posts && $skipped_post_count == $total_posts ) {
					esc_html_e( 'No announcement found', 'directorist-announcement' );
				}
				?>
			</div>
		</div>
		<?php
	}

	public static function get_all_user_emails() {
		$result = array();
		$number = 300;

		// Initiate first query
		$args = array(
			'role__not_in' => 'Administrator',
			'fields'       => 'user_email',
			'paged'        => 1,
			'number'       => $number,
		);

		$query  = new WP_User_Query( $args );
		$users  = (array) $query->get_results();
		$result = array_merge( $users, $result );

		$total = $query->get_total();

		if ( $total <= $number ) {
			return array_filter( $result );
		}

		$number_of_loops = ceil( $total / $number );

		// Run subsequent queries
		for ( $i = 2; $i <= $number_of_loops; $i++ ) {
			$args   = array(
				'role__not_in' => 'Administrator',
				'fields'       => 'user_email',
				'paged'        => $i,
				'number'       => $number,
			);
			$query  = new WP_User_Query( $args );
			$users  = (array) $query->get_results();
			$result = array_merge( $users, $result );
		}

		return array_filter( $result );
	}
}
