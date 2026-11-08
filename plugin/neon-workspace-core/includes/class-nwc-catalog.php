<?php
/**
 * Business categories and the service types each one offers.
 *
 * A business only sees the types of its own category. Every category ends with "Other", which
 * takes the business's own wording. Each type says how it is booked:
 *   appointment - one customer at a time;
 *   class       - several people share the same time (uses the service's seats);
 *   event       - a one-off occasion with seats.
 *
 * To add a category, add one entry here. Keys are stored, so never rename an existing key.
 * Filter: nwc_business_categories.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Catalog {

	const OTHER = 'other';

	public static function categories() {
		$c = array(
			'dance_studio'  => array( 'label' => 'Dance studio / academy', 'types' => array(
				'dance_class'     => array( 'Dance class', 'class' ),
				'group_class'     => array( 'Group class', 'class' ),
				'private_lesson'  => array( 'Individual lesson', 'appointment' ),
				'trial_class'     => array( 'Trial class', 'class' ),
				'dance_workshop'  => array( 'Workshop', 'class' ),
				'choreography'    => array( 'Choreography session', 'appointment' ),
				'showcase'        => array( 'Performance or showcase', 'event' ),
				'studio_rental'   => array( 'Studio rental', 'appointment' ),
				'practice'        => array( 'Practice session', 'appointment' ),
				'rehearsal'       => array( 'Rehearsal', 'appointment' ),
			) ),
			'music_academy' => array( 'label' => 'Music academy / studio', 'types' => array(
				'music_individual' => array( 'Individual lesson', 'appointment' ),
				'music_group'     => array( 'Group class', 'class' ),
				'music_trial'     => array( 'Trial lesson', 'appointment' ),
				'music_workshop'  => array( 'Workshop', 'class' ),
				'recital'         => array( 'Recital', 'event' ),
				'music_studio'    => array( 'Studio rental', 'appointment' ),
				'music_practice'  => array( 'Practice session', 'appointment' ),
				'music_rehearsal' => array( 'Rehearsal', 'appointment' ),
				'recording'       => array( 'Recording session', 'appointment' ),
			) ),
			'restaurant'    => array( 'label' => 'Restaurant', 'types' => array(
				'table_booking'   => array( 'Table reservation', 'appointment' ),
				'private_dining'  => array( 'Private dining', 'appointment' ),
				'catering'        => array( 'Catering', 'appointment' ),
				'restaurant_event' => array( 'Event or set menu', 'event' ),
			) ),
			'cafe'          => array( 'label' => 'Cafe', 'types' => array(
				'cafe_table'      => array( 'Table reservation', 'appointment' ),
				'cafe_workshop'   => array( 'Workshop (coffee, baking, art)', 'class' ),
				'cafe_event'      => array( 'Event or open mic', 'event' ),
				'cafe_party'      => array( 'Party booking', 'appointment' ),
			) ),
			'hotel'         => array( 'label' => 'Hotel / hospitality', 'types' => array(
				'banquet'         => array( 'Banquet or event hall', 'appointment' ),
				'hotel_spa'       => array( 'Spa treatment', 'appointment' ),
				'transfer'        => array( 'Airport or station transfer', 'appointment' ),
				'tour'            => array( 'Tour or activity', 'class' ),
			) ),
			'gym'           => array( 'label' => 'Gym / fitness centre', 'types' => array(
				'gym_group'       => array( 'Group fitness class', 'class' ),
				'gym_pt'          => array( 'Personal training session', 'appointment' ),
				'gym_trial'       => array( 'Trial session', 'appointment' ),
				'gym_assessment'  => array( 'Fitness assessment', 'appointment' ),
				'gym_diet'        => array( 'Diet consultation', 'appointment' ),
				'gym_yoga'        => array( 'Yoga or Zumba class', 'class' ),
			) ),
			'salon'         => array( 'label' => 'Salon / beauty / spa', 'types' => array(
				'haircut'         => array( 'Haircut & styling', 'appointment' ),
				'hair_colour'     => array( 'Hair colouring', 'appointment' ),
				'hair_treatment'  => array( 'Hair treatment', 'appointment' ),
				'skin_care'       => array( 'Facial & skin care', 'appointment' ),
				'nails'           => array( 'Manicure & pedicure', 'appointment' ),
				'waxing'          => array( 'Waxing & threading', 'appointment' ),
				'salon_makeup'    => array( 'Bridal & party makeup', 'appointment' ),
				'spa'             => array( 'Spa & massage', 'appointment' ),
			) ),
			'real_estate'   => array( 'label' => 'Real estate agency / broker / developer', 'types' => array(
				'site_visit'      => array( 'Site visit', 'appointment' ),
				'property_consult' => array( 'Property consultation', 'appointment' ),
				'valuation'       => array( 'Property valuation', 'appointment' ),
				'loan_consult'    => array( 'Home-loan consultation', 'appointment' ),
			) ),
			// Earlier categories. Kept so saved businesses keep their types; not offered for new ones.
			'fitness'       => array( 'label' => 'Fitness coaching', 'legacy' => true, 'types' => array(
				'personal_training' => array( 'Personal training session', 'appointment' ),
				'group_fitness'   => array( 'Group fitness class', 'class' ),
				'yoga'            => array( 'Yoga class', 'class' ),
				'online_coaching' => array( 'Online coaching session', 'appointment' ),
				'assessment'      => array( 'Fitness assessment', 'appointment' ),
				'bootcamp'        => array( 'Bootcamp or event', 'event' ),
			) ),
			'makeup_artist' => array( 'label' => 'Makeup artist', 'legacy' => true, 'types' => array(
				'bridal_makeup'   => array( 'Bridal makeup', 'appointment' ),
				'party_makeup'    => array( 'Party makeup', 'appointment' ),
				'shoot_makeup'    => array( 'Photoshoot makeup', 'appointment' ),
				'hairstyling'     => array( 'Hairstyling', 'appointment' ),
				'makeup_lesson'   => array( 'Makeup lesson', 'class' ),
			) ),
			'consulting'    => array( 'label' => 'Consulting', 'legacy' => true, 'types' => array(
				'consultation'    => array( 'Consultation', 'appointment' ),
				'discovery_call'  => array( 'Discovery call', 'appointment' ),
				'strategy'        => array( 'Strategy session', 'appointment' ),
				'follow_up'       => array( 'Follow-up session', 'appointment' ),
				'consult_workshop' => array( 'Workshop', 'class' ),
			) ),
			'events'        => array( 'label' => 'Events', 'legacy' => true, 'types' => array(
				'event_entry'     => array( 'Event entry or ticket', 'event' ),
				'event_workshop'  => array( 'Workshop', 'class' ),
				'venue'           => array( 'Venue booking', 'appointment' ),
				'event_planning'  => array( 'Planning consultation', 'appointment' ),
			) ),
			'freelance'     => array( 'label' => 'Freelance', 'legacy' => true, 'types' => array(
				'project_call'    => array( 'Project consultation', 'appointment' ),
				'intro_call'      => array( 'Discovery call', 'appointment' ),
				'review'          => array( 'Review or feedback session', 'appointment' ),
				'training'        => array( 'Training session', 'class' ),
			) ),
			'other'         => array( 'label' => 'Other business', 'types' => array(
				'appointment'     => array( 'Appointment', 'appointment' ),
				'general_consult' => array( 'Consultation', 'appointment' ),
				'class'           => array( 'Class', 'class' ),
				'event'           => array( 'Event', 'event' ),
			) ),
		);
		return apply_filters( 'nwc_business_categories', $c );
	}

	/** Stored values may be a key or an older label ("Dance studio"). Unknown or empty → other. */
	public static function category_key( $value ) {
		$v = strtolower( trim( (string) $value ) );
		$old_labels = array( 'salon' => 'salon', 'dance studio' => 'dance_studio', 'other service business' => 'other', 'gym' => 'gym', 'restaurant' => 'restaurant', 'cafe' => 'cafe', 'hotel' => 'hotel' );
		if ( isset( $old_labels[ $v ] ) ) {
			return $old_labels[ $v ];
		}
		foreach ( self::categories() as $k => $c ) {
			if ( $v === $k || strtolower( $c['label'] ) === $v ) {
				return $k;
			}
		}
		return 'other';
	}

	public static function category_label( $value ) {
		$k = self::category_key( $value );
		return self::categories()[ $k ]['label'];
	}

	/** Types for one category, with Other last. @return array key → {label, mode} */
	public static function types( $category ) {
		$all = self::categories();
		$c   = $all[ self::category_key( $category ) ];
		$out = array();
		foreach ( $c['types'] as $k => $t ) {
			$out[ $k ] = array( 'label' => $t[0], 'mode' => $t[1] );
		}
		$out[ self::OTHER ] = array( 'label' => 'Other', 'mode' => '' );
		return $out;
	}

	/** Label of any known type key, whatever category it came from. */
	public static function type_label( $key, $custom = '' ) {
		if ( self::OTHER === $key ) {
			return '' !== (string) $custom ? (string) $custom : 'Other';
		}
		foreach ( self::categories() as $c ) {
			if ( isset( $c['types'][ $key ] ) ) {
				return $c['types'][ $key ][0];
			}
		}
		return '' !== (string) $custom ? (string) $custom : '';
	}

	/**
	 * For the app: categories with their type lists, in display order. Earlier categories are marked
	 * legacy: they still show for a business that has one, but are not offered when choosing.
	 * With a Workspace, its own saved "Other" types are listed for reuse.
	 */
	public static function for_app( $workspace_id = 0 ) {
		$out    = array();
		$custom = $workspace_id ? NWC_Industry::list_values( $workspace_id, 'service_type' ) : array();
		foreach ( self::categories() as $k => $c ) {
			$types = array();
			foreach ( self::types( $k ) as $tk => $t ) {
				$types[] = array( 'key' => $tk, 'label' => $t['label'], 'mode' => $t['mode'] );
			}
			$out[] = array( 'key' => $k, 'label' => $c['label'], 'legacy' => ! empty( $c['legacy'] ), 'types' => $types, 'saved_custom' => $custom );
		}
		return $out;
	}

	/** Category keys a new or converted Workspace may choose. */
	public static function selectable() {
		return array_keys( array_filter( self::categories(), function ( $c ) {
			return empty( $c['legacy'] );
		} ) );
	}

	/**
	 * Checks a service's type against its business's category and works out how it is booked.
	 *
	 * @param array       $row   Cleaned service fields (may hold type_key, type_custom, capacity).
	 * @param object|null $old   The service before this save.
	 * @param string      $category The business category.
	 * @return array|WP_Error The row with type_key, type_custom and service_type set.
	 */
	public static function apply_type( $row, $old, $category ) {
		$key    = array_key_exists( 'type_key', $row ) ? sanitize_key( (string) $row['type_key'] ) : ( $old ? (string) $old->type_key : '' );
		$custom = array_key_exists( 'type_custom', $row ) ? trim( (string) $row['type_custom'] ) : ( $old ? (string) $old->type_custom : '' );
		if ( '' === $key && ! $old ) {
			return NWC::err( 'Choose a service type.' );
		}
		if ( '' === $key ) {
			return $row; // An older service saved without touching its type.
		}
		$types = self::types( $category );
		$kept  = $old && $key === (string) $old->type_key; // A type from a former category can stay.
		if ( ! isset( $types[ $key ] ) && ! $kept ) {
			return NWC::err( 'That service type does not belong to this business category.' );
		}
		if ( self::OTHER === $key ) {
			if ( '' === $custom ) {
				return NWC::err( 'Enter your own service type.' );
			}
			$row['type_custom'] = mb_substr( sanitize_text_field( $custom ), 0, 120 );
		} else {
			$row['type_custom'] = '';
		}
		$row['type_key'] = $key;
		$cap  = isset( $row['capacity'] ) ? (int) $row['capacity'] : ( $old ? (int) $old->capacity : 1 );
		$mode = isset( $types[ $key ] ) ? $types[ $key ]['mode'] : '';
		if ( '' === $mode ) {
			$mode = $cap > 1 ? 'class' : 'appointment';
		}
		$row['service_type'] = $mode;
		return $row;
	}
}
