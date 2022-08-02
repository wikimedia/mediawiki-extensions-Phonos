( () => {
	function init( $content ) {
		$content.find( '.ext-phonos-ipa' ).each( function () {
			const $span = $( this );
			// On first click, add the audio player.
			$span.one( 'click', function () {
				// @TODO Make proper UI. For now, just append an audio player.
				const $audio = $( '<audio>' );
				$audio.prop( 'controls', true );
				$audio.prop( 'autoplay', true );

				// If a file URL is set, use it instead of querying action=phonos.
				// @TODO: This can be leveraged to pull cached files once we implement that system,
				//   avoiding the then-unnecessary action=phonos API request.
				if ( $span.data( 'phonos-file' ) ) {
					$audio.attr( 'src', $span.data( 'phonos-file' ) );
					$span.after( $audio );
					return;
				}

				// Make a request to action=phonos to fetch the audio.
				( new mw.Api() ).get( {
					action: 'phonos',
					ipa: $span.data( 'phonos-ipa' ),
					text: $span.data( 'phonos-text' ),
					lang: $span.data( 'phonos-lang' )
				} ).done( function ( response ) {
					$audio.attr( 'src', 'data:audio/wav;base64,' + response.phonos.audioData );
					$span.after( $audio );
				} );
			} );
		} );
	}

	mw.hook( 'wikipage.content' ).add( init );
} )();
