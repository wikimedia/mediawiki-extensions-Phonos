/**
 * @class
 * @constructor
 * @extends OO.ui.ButtonWidget
 * @mixin OO.ui.mixin.PendingElement
 * @param {Object} [phonosData] Phonos data parameters.
 */
function PhonosButton( phonosData ) {
	this.phonosData = phonosData;

	// Parent constructor.
	PhonosButton.super.call( this, {
		classes: [ 'ext-phonos-PhonosButton' ],
		icon: 'volumeUp',
		label: this.phonosData.ipa,
		framed: false
	} );

	// Mixin constructor.
	OO.ui.mixin.PendingElement.call( this, { $pending: this.$button } );

	// This HTMLAudioElement will be instantiated once.
	this.audio = null;

	// Create a popup for error messages.
	this.popup = null;
	if ( this.phonosData.errorMsg === 'phonos-file-not-found' ) {
		// @todo Handle other error messages. The idea is that the errorMsg
		this.setFlags( [ 'destructive' ] );
		this.setIcon( 'volumeOff' );
		const error = mw.message( 'phonos-file-not-found', [ this.phonosData.file ] ).parse();
		this.popup = new OO.ui.PopupWidget( {
			$content: $( '<p>' ).append( error ),
			padded: true
		} );
		this.$element.append( this.popup.$element );
	}

	this.connect( this, { click: this.onClick } );
}

OO.inheritClass( PhonosButton, OO.ui.ButtonWidget );
OO.mixinClass( PhonosButton, OO.ui.mixin.PendingElement );

PhonosButton.prototype.onClick = function () {
	// A popup exists, so no audio can be played.
	if ( this.popup ) {
		this.popup.toggle();
		return '';
	}

	// Already playing, so pause and reset to the beginning.
	if ( this.audio && !this.audio.paused ) {
		this.audio.pause();
		return;
	}

	// Already loaded, but has ended so play again from the beginning.
	if ( this.audio ) {
		this.audio.currentTime = 0;
		this.audio.play();
		return;
	}

	// Not loaded yet, but has a src URL so use that.
	if ( !this.audio && this.phonosData.src ) {
		this.pushPending();
		this.audio = this.getAudioEl( this.phonosData.src );
		// Play once after loading.
		this.audio.addEventListener( 'canplaythrough', () => {
			this.popPending();
			this.audio.play();
		}, { once: true } );
		return;
	}

	// Not loaded yet, and needs to fetch audio from the action=phonos API.
	if ( !this.audio ) {
		this.pushPending();
		( new mw.Api() ).get( {
			action: 'phonos',
			ipa: this.phonosData.ipa,
			text: this.phonosData.text,
			lang: this.phonosData.lang
		} ).done( ( response ) => {
			const srcData = 'data:audio/wav;base64,' + response.phonos.audioData;
			this.audio = this.getAudioEl( srcData );
			// Play once after loading.
			this.audio.addEventListener( 'canplaythrough', () => {
				this.audio.play();
			}, { once: true } );
		} ).fail( ( err ) => {
			mw.error( err );
			// @todo Add error popup and button state.
		} ).always( () => {
			this.popPending();
		} );
	}
};

PhonosButton.prototype.getAudioEl = function ( src ) {
	const audio = new Audio( src );
	audio.addEventListener( 'playing', () => {
		this.setFlags( { progressive: true } );
	} );
	audio.addEventListener( 'paused', () => {
		this.setFlags( { progressive: false } );
	} );
	audio.addEventListener( 'ended', () => {
		this.setFlags( { progressive: false } );
	} );
	return audio;
};

module.exports = PhonosButton;