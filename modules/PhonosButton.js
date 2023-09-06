/**
 * @class
 * @constructor
 * @extends OO.ui.ButtonWidget
 * @mixin OO.ui.mixin.PendingElement
 * @param {Object} [config] Configuration parameters.
 */
function PhonosButton( config ) {
	// Parent constructor.
	PhonosButton.super.call( this, $.extend( {
		$overlay: true,
		popup: {
			classes: [ 'ext-phonos-error-popup' ],
			padded: true
		}
	}, config ) );

	// Mixin constructor.
	OO.ui.mixin.PendingElement.call( this, { $pending: this.$button } );

	this.phonosData = this.getData();

	// T315404: Wrap output element in data-nosnippet
	this.$element.attr( 'data-nosnippet', '' );

	if ( config.ariaLabel ) {
		this.$button.attr( 'aria-label', config.ariaLabel );
	}

	// This HTMLAudioElement will be instantiated once.
	this.audio = null;

	// Add any error message to the popup.
	this.getPopup().$body.append( $( '<p>' ).append( this.getErrorMessage() ) );

	this.connect( this, { click: 'playHandler' } );
}

OO.inheritClass( PhonosButton, OO.ui.PopupButtonWidget );
OO.mixinClass( PhonosButton, OO.ui.mixin.PendingElement );

/**
 * @inheritdoc
 */
PhonosButton.static.reusePreInfuseDOM = function ( node, config ) {
	// Store aria-label attribute so that it can be re-added in the constructor above.
	config.ariaLabel = node.firstElementChild.getAttribute( 'aria-label' );
	return config;
};

/**
 * File play handler: play or pause the audio.
 *
 * @protected
 * @return {undefined|boolean} False to prevent default if event is handled
 */
PhonosButton.prototype.playHandler = function () {
	const startedAt = mw.now();
	this.track( 'counter.MediaWiki.extension.Phonos.IPA.click', 1 );

	// Popup content exists, so no audio can be played.
	if ( this.getPopup().$body.text() !== '' ) {
		this.track( 'counter.MediaWiki.extension.Phonos.IPA.error', 1 );
		return false;
	} else {
		// Close popup that opens by default, still needed if keyboard nav
		this.getPopup().toggle( false );
	}

	// Already playing, so pause and reset to the beginning.
	if ( this.audio && !this.audio.paused ) {
		this.audio.pause();
		return false;
	}

	// Already loaded, but has ended so play again from the beginning.
	if ( this.audio ) {
		this.audio.currentTime = 0;
		this.audio.play();
		// Track replay clicks
		this.track( 'counter.MediaWiki.extension.Phonos.IPA.replay', 1 );
		return false;
	}

	// Not loaded yet, but has a src URL so use that.
	if ( !this.audio && this.getHref() ) {
		this.pushPending();
		this.audio = this.getAudioEl( this.getHref() );
		// Play once after loading.
		this.audio.addEventListener( 'canplaythrough', () => {
			this.popPending();

			// Record the duration once to reduce access to Audio API.
			this.duration = this.audio.duration;
			if ( this.usesAnimation ) {
				this.$button.css( 'animation-duration', ( this.duration / this.audio.playbackRate ) + 's' );
			}

			this.audio.play();
			// Track completion time
			const finishedAt = mw.now() - startedAt;
			this.track( 'timing.MediaWiki.extension.Phonos.IPA.can_play_through', finishedAt );
		}, { once: true } );
	}
	return false;
};

/**
 * @private
 * @param {string} src
 * @return {HTMLAudioElement}
 */
PhonosButton.prototype.getAudioEl = function ( src ) {
	const audio = new Audio( src );
	audio.addEventListener( 'playing', () => {
		this.setFlags( { progressive: true } );
	} );
	audio.addEventListener( 'pause', () => {
		this.setFlags( { progressive: false } );
	} );
	if ( !this.usesAnimation ) {
		audio.addEventListener( 'timeupdate', () => {
			const position = ( audio.currentTime / this.duration ) * 100;
			this.$button.css( 'background-size', position + '% 100%' );
		} );
	}
	audio.onerror = this.handleMissingFile.bind( this );
	mw.hook( 'ext.Phonos.audio' ).fire( audio );
	return audio;
};

/**
 * Whether the button should use animation. Determined by media query
 * `(prefers-reduced-motion: reduce)`, in which case MediaWiki blocks all CSS animation.
 *
 * @private
 * @property {boolean}
 */
PhonosButton.prototype.usesAnimation = !matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

/**
 * Create and return an error message if necessary.
 *
 * @return {null|string}
 */
PhonosButton.prototype.getErrorMessage = function () {
	if ( !this.phonosData.error ) {
		return null;
	}

	// Messages that can be used here:
	// * phonos-audio-conversion-error
	// * phonos-directory-error
	// * phonos-engine-error
	// * phonos-storage-error
	// * phonos-wikibase-api-error
	// * phonos-wikibase-invalid-entity-lexeme
	// * phonos-wikibase-not-found
	// * phonos-wikibase-no-ipa
	let error = this.phonosData.error;

	// If a file was given, we know this is an error specifically involving the file
	// and we want to construct a link to the file page.
	if ( this.phonosData.file ) {
		let fileNameOrLink = this.phonosData.file;
		try {
			const fileTitle = new mw.Title( 'File:' + this.phonosData.file );
			const $link = $( '<a>' )
				.attr( 'href', fileTitle.getUrl() )
				.text( fileTitle.getMainText() );
			fileNameOrLink = $link.prop( 'outerHTML' );
		} catch ( e ) {
			// Use unlinked form if there's an issue constructing the link.
		}
		// Messages that can be used here:
		// * phonos-file-not-found
		// * phonos-file-not-audio
		error = mw.message( this.phonosData.error, [ fileNameOrLink ] ).text();
	}

	return error;
};

/**
 * This is called when there's an error when attempting playback. We assume in this case
 * the file somehow went missing. In any case, re-trying is probably the best advice for the user.
 *
 * @private
 */
PhonosButton.prototype.handleMissingFile = function () {
	this.popPending();
	this.$element.addClass( 'ext-phonos-error' );
	this.setIcon( 'volumeOff' );
	const $link = $( '<a>' )
		.attr( 'href', mw.util.getUrl( mw.config.get( 'wgPageName' ), { action: 'purge' } ) )
		.text( mw.message( 'phonos-purge-needed-error-link' ) );
	this.getPopup().$body.append(
		$( '<p>' ).append(
			mw.message( 'phonos-purge-needed-error' ).text() + '&nbsp;',
			$link
		)
	);
	this.getPopup().toggle( true );

	// Set up click listener for the link so that users with JS
	// aren't shown the action=purge confirmation screen.
	$link.on( 'click', ( e ) => {
		this.setIcon( 'reload' );
		e.preventDefault();
		mw.loader.using( 'mediawiki.api' ).done( () => {
			new mw.Api().post( {
				action: 'purge',
				pageids: mw.config.get( 'wgArticleId' )
			} ).always( () => {
				// The browser *should* bring the user back to the same scroll position.
				location.reload();
			} );
		} );
	} );
};

/**
 * This is called when we need to track a metric by wiki and by lang
 * through statsv
 *
 * @param {string} baseMetricName
 * @param {number} value
 * @private
 */
PhonosButton.prototype.track = function ( baseMetricName, value ) {
	const dbName = mw.config.get( 'wgDBname' );
	const lang = mw.config.get( 'wgContentLanguage' );
	mw.track( `${baseMetricName}.by_wiki.${dbName}`, value );
	mw.track( `${baseMetricName}.by_lang.${lang}`, value );
};

module.exports = PhonosButton;
