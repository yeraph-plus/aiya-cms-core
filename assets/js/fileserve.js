/**
 * File configuration metabox (Admin/FileServeMetabox): builds the two layers
 * from the bootstrap data — one tab per data group to fill in, one preview
 * read back through the same service the front end uses.
 *
 * The panels are the editor's view; the storage is the hidden JSON field,
 * which this keeps in step on every change so a plain Publish/Update carries
 * the same value the buttons do. Data reaches the DOM through textContent and
 * createElement only.
 */
( function ( $ ) {
	'use strict';

	var node = document.getElementById( 'aiya-fileserve-bootstrap' );
	var root = document.getElementById( 'aiya-fileserve' );
	if ( ! node || ! root ) {
		return;
	}

	var boot;
	try {
		boot = JSON.parse( node.textContent );
	} catch ( error ) {
		return;
	}
	if ( ! boot || ! boot.adapters ) {
		return;
	}

	var hidden = document.getElementById( boot.inputId );
	var tabs = document.getElementById( 'aiya-fileserve-tabs' );
	var panels = document.getElementById( 'aiya-fileserve-panels' );
	var status = document.getElementById( 'aiya-fileserve-status' );
	var previewBox = document.getElementById( 'aiya-fileserve-preview-box' );
	var addSelect = document.getElementById( 'aiya-fileserve-add' );
	var addButton = document.getElementById( 'aiya-fileserve-add-confirm' );
	var saveButton = document.getElementById( 'aiya-fileserve-save' );
	var previewButton = document.getElementById( 'aiya-fileserve-preview' );
	if ( ! hidden || ! tabs || ! panels ) {
		return;
	}

	var adapters = {};
	boot.adapters.forEach( function ( adapter ) {
		adapters[ adapter.id ] = adapter;
	} );

	/**
	 * The stored groups must be an object keyed by group id. A PHP empty
	 * array arrives as a JSON array, which reads as truthy here and would
	 * make JSON.stringify drop every group added afterwards — so an array
	 * (or anything that is not an object) collapses to an empty object.
	 */
	function normalizeConfig( value ) {
		if ( Array.isArray( value ) || ! value || typeof value !== 'object' ) {
			return {};
		}
		return value;
	}

	var state = {
		config: normalizeConfig( boot.config ),
		nextId: String( boot.nextId || '1' ),
		active: null
	};

	function defaultsFor( adapter ) {
		var group = { adapter: adapter.id };
		adapter.fields.forEach( function ( field ) {
			group[ field.id ] = field.default;
		} );
		return group;
	}

	function groupIds() {
		return Object.keys( state.config );
	}

	function labelFor( id ) {
		var group = state.config[ id ] || {};
		var adapter = adapters[ group.adapter ] || { label: String( group.adapter || '' ) };
		var title = group.title === null || typeof group.title === 'undefined' ? '' : String( group.title );

		return title !== '' ? title : adapter.label + ' · ' + id;
	}

	/** Reads every rendered control back into the stored object. */
	function collect() {
		var inputs = panels.querySelectorAll( '[data-field][data-group]' );
		Array.prototype.forEach.call( inputs, function ( input ) {
			var id = input.getAttribute( 'data-group' );
			if ( ! state.config[ id ] ) {
				return;
			}
			var field = input.getAttribute( 'data-field' );
			state.config[ id ][ field ] = input.type === 'number' ? Number( input.value || 0 ) : input.value;
		} );
		hidden.value = JSON.stringify( state.config );
	}

	function control( id, field ) {
		var group = state.config[ id ] || {};
		var value = Object.prototype.hasOwnProperty.call( group, field.id ) ? group[ field.id ] : field.default;
		var input;

		if ( field.type === 'select' ) {
			input = document.createElement( 'select' );
			Object.keys( field.options || {} ).forEach( function ( key ) {
				var option = document.createElement( 'option' );
				option.value = key;
				option.textContent = field.options[ key ];
				input.appendChild( option );
			} );
			input.value = String( value === null || typeof value === 'undefined' ? '' : value );
		} else {
			input = document.createElement( 'input' );
			input.type = field.type === 'number' ? 'number' : ( field.type === 'url' ? 'url' : 'text' );
			if ( field.type === 'number' ) {
				if ( typeof field.min !== 'undefined' ) {
					input.min = String( field.min );
				}
				input.step = typeof field.step !== 'undefined' ? String( field.step ) : '1';
			}
			input.value = value === null || typeof value === 'undefined' ? '' : String( value );
		}

		input.className = field.type === 'number' ? 'small-text' : 'regular-text';
		input.setAttribute( 'data-field', field.id );
		input.setAttribute( 'data-group', id );
		input.addEventListener( 'input', collect );
		input.addEventListener( 'change', collect );

		return input;
	}

	function render() {
		var ids = groupIds();
		if ( ids.indexOf( state.active ) === -1 ) {
			state.active = ids.length > 0 ? ids[ 0 ] : null;
		}

		tabs.textContent = '';
		panels.textContent = '';

		ids.forEach( function ( id ) {
			var adapter = adapters[ state.config[ id ].adapter ] || { label: String( state.config[ id ].adapter || '' ), fields: [] };

			var tab = document.createElement( 'button' );
			tab.type = 'button';
			tab.className = 'button aiya-fileserve__tab' + ( id === state.active ? ' is-active' : '' );
			tab.textContent = labelFor( id );
			tab.addEventListener( 'click', function () {
				collect();
				state.active = id;
				render();
			} );
			tabs.appendChild( tab );

			var panel = document.createElement( 'div' );
			panel.className = 'aiya-fileserve__panel' + ( id === state.active ? ' is-active' : '' );
			panel.setAttribute( 'data-group', id );

			var head = document.createElement( 'p' );
			head.className = 'aiya-fileserve__adapter';
			var badge = document.createElement( 'span' );
			badge.className = 'aiya-core-badge';
			badge.textContent = adapter.label;
			head.appendChild( badge );
			panel.appendChild( head );

			( adapter.fields || [] ).forEach( function ( field ) {
				var wrap = document.createElement( 'p' );
				wrap.className = 'aiya-fileserve__field';

				var controlId = 'aiya-fileserve-' + id + '-' + field.id;
				var label = document.createElement( 'label' );
				label.setAttribute( 'for', controlId );
				label.appendChild( document.createTextNode( field.label ) );

				var input = control( id, field );
				input.id = controlId;

				wrap.appendChild( label );
				wrap.appendChild( document.createElement( 'br' ) );
				wrap.appendChild( input );

				if ( field.description ) {
					wrap.appendChild( document.createElement( 'br' ) );
					var description = document.createElement( 'span' );
					description.className = 'description';
					description.textContent = field.description;
					wrap.appendChild( description );
				}

				panel.appendChild( wrap );
			} );

			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'button-link aiya-fileserve__remove';
			remove.textContent = boot.strings.remove;
			remove.addEventListener( 'click', function () {
				if ( ! window.confirm( boot.strings.confirmRemove ) ) {
					return;
				}
				collect();
				delete state.config[ id ];
				if ( state.active === id ) {
					state.active = null;
				}
				render();
			} );
			panel.appendChild( remove );

			panels.appendChild( panel );
		} );

		if ( ids.length === 0 ) {
			var empty = document.createElement( 'p' );
			empty.className = 'description';
			empty.textContent = boot.strings.empty;
			panels.appendChild( empty );
		}

		hidden.value = JSON.stringify( state.config );
	}

	function sizeText( bytes ) {
		if ( bytes >= 1073741824 ) {
			return ( bytes / 1073741824 ).toFixed( 1 ) + ' GB';
		}
		if ( bytes >= 1048576 ) {
			return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		}
		if ( bytes >= 1024 ) {
			return ( bytes / 1024 ).toFixed( 0 ) + ' KB';
		}
		return bytes > 0 ? bytes + ' B' : '';
	}

	function renderPreview( lists ) {
		previewBox.textContent = '';
		if ( lists.length === 0 ) {
			var empty = document.createElement( 'p' );
			empty.className = 'description';
			empty.textContent = boot.strings.noLists;
			previewBox.appendChild( empty );
			return;
		}

		lists.forEach( function ( list ) {
			var block = document.createElement( 'div' );
			block.className = 'aiya-fileserve__list';

			var head = document.createElement( 'h4' );
			head.textContent = list.title !== '' ? list.title : labelFor( list.id );
			var price = document.createElement( 'span' );
			price.className = 'description';
			price.textContent =
				list.price > 0
					? ' ' + boot.strings.credits.replace( '%d', String( list.price ) )
					: ' ' + boot.strings.free;
			head.appendChild( price );
			block.appendChild( head );

			if ( list.error ) {
				var error = document.createElement( 'p' );
				error.className = 'aiya-fileserve__error notice notice-warning inline';
				error.textContent = list.error.message;
				block.appendChild( error );
			}

			if ( list.items.length > 0 ) {
				var table = document.createElement( 'table' );
				table.className = 'wp-list-table widefat fixed striped';
				var thead = document.createElement( 'thead' );
				var headRow = document.createElement( 'tr' );
				[ boot.strings.name, boot.strings.type, boot.strings.size ].forEach( function ( caption ) {
					var cell = document.createElement( 'th' );
					cell.textContent = caption;
					headRow.appendChild( cell );
				} );
				thead.appendChild( headRow );
				table.appendChild( thead );

				var body = document.createElement( 'tbody' );
				list.items.forEach( function ( item ) {
					var row = document.createElement( 'tr' );
					[ item.name, item.type, sizeText( item.size ) ].forEach( function ( value ) {
						var cell = document.createElement( 'td' );
						cell.textContent = value;
						row.appendChild( cell );
					} );
					body.appendChild( row );
				} );
				table.appendChild( body );
				block.appendChild( table );
			}

			previewBox.appendChild( block );
		} );
	}

	function send( action, done ) {
		collect();
		// The configuration travels under the same field name the server reads
		// for the classic save, so both paths post one shape.
		var payload = {
			action: action,
			nonce: boot.nonce,
			post_id: boot.postId
		};
		payload[ boot.inputId ] = JSON.stringify( state.config );
		$.post( ajaxurl, payload )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					status.textContent = res && res.data && res.data.message ? res.data.message : boot.strings.requestFailed;
					return;
				}
				done( res.data );
			} )
			.fail( function () {
				status.textContent = boot.strings.requestFailed;
			} );
	}

	if ( addButton && addSelect ) {
		addButton.addEventListener( 'click', function () {
			var adapter = adapters[ addSelect.value ];
			if ( ! adapter ) {
				return;
			}
			collect();
			state.config[ state.nextId ] = defaultsFor( adapter );
			state.active = state.nextId;
			state.nextId = String( Number( state.nextId ) + 1 );
			render();
			status.textContent = '';
		} );
	}

	if ( saveButton ) {
		saveButton.addEventListener( 'click', function () {
			status.textContent = boot.strings.saving;
			send( boot.actions.save, function ( data ) {
				state.config = normalizeConfig( data.config || state.config );
				state.nextId = String( data.nextId || state.nextId );
				render();
				status.textContent = data.message || '';
			} );
		} );
	}

	if ( previewButton ) {
		previewButton.addEventListener( 'click', function () {
			status.textContent = boot.strings.loading;
			send( boot.actions.preview, function ( data ) {
				status.textContent = data.message || '';
				renderPreview( data.lists || [] );
			} );
		} );
	}

	render();
} )( jQuery );
