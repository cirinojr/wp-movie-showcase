import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import './style.scss';

const MIN_QUERY_LENGTH = 3;
const DEBOUNCE_DELAY = 350;
const SUGGESTION_STATUS_DELAY = 150;
const SUGGESTION_CACHE_LIMIT = 50;

const suggestionCache = new Map();

const fieldLabels = {
	year: __( 'Year', 'wp-movie-showcase' ),
	rated: __( 'Rated', 'wp-movie-showcase' ),
	runtime: __( 'Runtime', 'wp-movie-showcase' ),
	genre: __( 'Genre', 'wp-movie-showcase' ),
	director: __( 'Director', 'wp-movie-showcase' ),
	plot: __( 'Plot', 'wp-movie-showcase' ),
};

const suggestionTypes = {
	movie: __( 'Movie', 'wp-movie-showcase' ),
	series: __( 'Series', 'wp-movie-showcase' ),
	episode: __( 'Episode', 'wp-movie-showcase' ),
};

function normalizeValue( value ) {
	if ( typeof value !== 'string' ) {
		return '';
	}

	const trimmedValue = value.trim();

	if ( '' === trimmedValue || 'N/A' === trimmedValue ) {
		return '';
	}

	return trimmedValue;
}

function normalizeQuery( value ) {
	return normalizeValue( value ).replace( /\s+/g, ' ' ).toLowerCase();
}

function isValidPosterUrl( url ) {
	const posterUrl = normalizeValue( url );

	if ( '' === posterUrl ) {
		return false;
	}

	try {
		const parsedUrl = new window.URL( posterUrl );

		return 'https:' === parsedUrl.protocol;
	} catch {
		return false;
	}
}

function appendField( documentRef, definitionList, key, value ) {
	const label = fieldLabels[ key ];
	const normalizedValue = normalizeValue( value );

	if ( ! label || '' === normalizedValue ) {
		return;
	}

	const term = documentRef.createElement( 'dt' );
	term.className = 'wp-movie-showcase__term';
	term.textContent = label;

	const description = documentRef.createElement( 'dd' );
	description.className = 'wp-movie-showcase__description';
	description.textContent = normalizedValue;

	definitionList.appendChild( term );
	definitionList.appendChild( description );
}

function createRatingMarkup( documentRef, value ) {
	const normalizedValue = normalizeValue( value );

	if ( '' === normalizedValue ) {
		return null;
	}

	const rating = documentRef.createElement( 'p' );
	rating.className = 'wp-movie-showcase__rating';

	const star = documentRef.createElement( 'span' );
	star.className = 'wp-movie-showcase__rating-star';
	star.setAttribute( 'aria-hidden', 'true' );
	star.textContent = '★';

	const score = documentRef.createElement( 'span' );
	score.className = 'wp-movie-showcase__rating-value';
	score.textContent = normalizedValue;

	const label = documentRef.createElement( 'span' );
	label.className = 'wp-movie-showcase__rating-label';
	label.textContent = __( 'IMDb Rating', 'wp-movie-showcase' );

	rating.appendChild( star );
	rating.appendChild( score );
	rating.appendChild( label );

	return rating;
}

function createMovieMarkup( rootElement, movie ) {
	const documentRef = rootElement.ownerDocument;
	const article = documentRef.createElement( 'article' );
	article.className = 'wp-movie-showcase__result-card';

	const poster = normalizeValue( movie.poster );

	if ( isValidPosterUrl( poster ) ) {
		const image = documentRef.createElement( 'img' );
		image.className = 'wp-movie-showcase__poster';
		image.src = poster;
		image.alt = normalizeValue( movie.title )
			? `${ normalizeValue( movie.title ) } ${ __(
					'poster',
					'wp-movie-showcase'
			  ) }`
			: __( 'Movie poster', 'wp-movie-showcase' );
		image.loading = 'lazy';
		image.decoding = 'async';
		image.width = 224;
		image.height = 336;
		image.referrerPolicy = 'no-referrer';
		image.addEventListener(
			'error',
			() => {
				image.remove();
				article.classList.add(
					'wp-movie-showcase__result-card--no-poster'
				);
			},
			{ once: true }
		);
		article.appendChild( image );
	} else {
		article.classList.add( 'wp-movie-showcase__result-card--no-poster' );
	}

	const body = documentRef.createElement( 'div' );
	body.className = 'wp-movie-showcase__content';

	const title = normalizeValue( movie.title );

	if ( '' !== title ) {
		const titleElement = documentRef.createElement( 'p' );
		titleElement.className = 'wp-movie-showcase__title';
		titleElement.textContent = title;
		body.appendChild( titleElement );
	}

	const rating = createRatingMarkup( documentRef, movie.imdb_rating );

	if ( rating ) {
		body.appendChild( rating );
	}

	const definitionList = documentRef.createElement( 'dl' );
	definitionList.className = 'wp-movie-showcase__details';

	appendField( documentRef, definitionList, 'year', movie.year );
	appendField( documentRef, definitionList, 'rated', movie.rated );
	appendField( documentRef, definitionList, 'runtime', movie.runtime );
	appendField( documentRef, definitionList, 'genre', movie.genre );
	appendField( documentRef, definitionList, 'director', movie.director );
	appendField( documentRef, definitionList, 'plot', movie.plot );

	body.appendChild( definitionList );
	article.appendChild( body );

	return article;
}

function createSuggestionLabel( suggestion ) {
	const suggestionType = normalizeValue( suggestion.type ).toLowerCase();

	return [
		normalizeValue( suggestion.year ),
		suggestionTypes[ suggestionType ] || normalizeValue( suggestion.type ),
	]
		.filter( Boolean )
		.join( ' • ' );
}

function createResultSkeleton( rootElement ) {
	const documentRef = rootElement.ownerDocument;
	const article = documentRef.createElement( 'article' );
	article.className =
		'wp-movie-showcase__result-card wp-movie-showcase__result-card--loading';
	article.setAttribute( 'aria-hidden', 'true' );

	const poster = documentRef.createElement( 'div' );
	poster.className =
		'wp-movie-showcase__skeleton wp-movie-showcase__skeleton--poster';
	article.appendChild( poster );

	const body = documentRef.createElement( 'div' );
	body.className = 'wp-movie-showcase__content';

	[
		'wp-movie-showcase__skeleton wp-movie-showcase__skeleton--title',
		'wp-movie-showcase__skeleton wp-movie-showcase__skeleton--line',
		'wp-movie-showcase__skeleton wp-movie-showcase__skeleton--line wp-movie-showcase__skeleton--line-wide',
		'wp-movie-showcase__skeleton wp-movie-showcase__skeleton--line',
	].forEach( ( className ) => {
		const line = documentRef.createElement( 'div' );
		line.className = className;
		body.appendChild( line );
	} );

	article.appendChild( body );

	return article;
}

function renderMessage( resultsContainer, message ) {
	resultsContainer.replaceChildren();

	const paragraph = resultsContainer.ownerDocument.createElement( 'p' );
	paragraph.className = 'wp-movie-showcase__message';
	paragraph.textContent = message;

	resultsContainer.appendChild( paragraph );
}

function setStatus( statusElement, message, state = 'idle' ) {
	statusElement.textContent = message;
	statusElement.dataset.state = '' === message ? 'idle' : state;
}

function setInputState( input, isInvalid ) {
	if ( isInvalid ) {
		input.setAttribute( 'aria-invalid', 'true' );
		return;
	}

	input.removeAttribute( 'aria-invalid' );
}

function setResultsBusy( resultsContainer, isBusy ) {
	if ( isBusy ) {
		resultsContainer.setAttribute( 'aria-busy', 'true' );
		return;
	}

	resultsContainer.removeAttribute( 'aria-busy' );
}

function updateButtonState( input, button ) {
	button.disabled = normalizeValue( input.value ).length < MIN_QUERY_LENGTH;
}

function cacheSuggestions( key, suggestions ) {
	if ( suggestionCache.has( key ) ) {
		suggestionCache.delete( key );
	}

	suggestionCache.set( key, suggestions );

	if ( suggestionCache.size > SUGGESTION_CACHE_LIMIT ) {
		suggestionCache.delete( suggestionCache.keys().next().value );
	}
}

function fetchSuggestions( query, signal ) {
	const key = normalizeQuery( query );

	if ( suggestionCache.has( key ) ) {
		return Promise.resolve( suggestionCache.get( key ) );
	}

	return apiFetch( {
		path: `/wp-movie-showcase/v1/suggestions?query=${ encodeURIComponent(
			query
		) }`,
		signal,
	} ).then( ( suggestions ) => {
		const normalizedSuggestions = Array.isArray( suggestions )
			? suggestions
			: [];

		cacheSuggestions( key, normalizedSuggestions );

		return normalizedSuggestions;
	} );
}

function createOptionPosterPlaceholder( documentRef ) {
	const placeholder = documentRef.createElement( 'div' );
	placeholder.className =
		'wp-movie-showcase__option-poster wp-movie-showcase__option-poster--empty';

	return placeholder;
}

function createOption( rootElement, suggestion, index ) {
	const documentRef = rootElement.ownerDocument;
	const option = documentRef.createElement( 'div' );
	option.className = 'wp-movie-showcase__option';
	option.id = `${ rootElement.dataset.listboxId }-${ index }-${ suggestion.imdb_id }`;
	option.setAttribute( 'role', 'option' );
	option.setAttribute( 'aria-selected', 'false' );
	option.dataset.imdbId = suggestion.imdb_id;

	if ( isValidPosterUrl( suggestion.poster ) ) {
		const image = documentRef.createElement( 'img' );
		image.className = 'wp-movie-showcase__option-poster';
		image.src = suggestion.poster;
		image.alt = '';
		image.loading = 'lazy';
		image.decoding = 'async';
		image.width = 40;
		image.height = 60;
		image.referrerPolicy = 'no-referrer';
		image.addEventListener(
			'error',
			() => {
				image.replaceWith(
					createOptionPosterPlaceholder( documentRef )
				);
			},
			{ once: true }
		);
		option.appendChild( image );
	} else {
		option.appendChild( createOptionPosterPlaceholder( documentRef ) );
	}

	const body = documentRef.createElement( 'div' );
	body.className = 'wp-movie-showcase__option-body';

	const title = documentRef.createElement( 'span' );
	title.className = 'wp-movie-showcase__option-title';
	title.textContent = normalizeValue( suggestion.title );
	body.appendChild( title );

	const meta = createSuggestionLabel( suggestion );

	if ( '' !== meta ) {
		const details = documentRef.createElement( 'span' );
		details.className = 'wp-movie-showcase__option-meta';
		details.textContent = meta;
		body.appendChild( details );
	}

	option.appendChild( body );

	return option;
}

function bindBlock( rootElement ) {
	const form = rootElement.querySelector( '.wp-movie-showcase__form' );
	const input = rootElement.querySelector( '.wp-movie-showcase__input' );
	const button = rootElement.querySelector( '.wp-movie-showcase__button' );
	const clearButton = rootElement.querySelector(
		'.wp-movie-showcase__clear'
	);
	const listbox = rootElement.querySelector(
		'.wp-movie-showcase__suggestions'
	);
	const statusElement = rootElement.querySelector(
		'.wp-movie-showcase__status'
	);
	const resultsContainer = rootElement.querySelector(
		'.wp-movie-showcase__results'
	);

	if (
		! form ||
		! input ||
		! button ||
		! clearButton ||
		! listbox ||
		! statusElement ||
		! resultsContainer
	) {
		return;
	}

	rootElement.dataset.listboxId = listbox.id;

	let suggestions = [];
	let activeIndex = -1;
	let debounceTimer = 0;
	let autocompleteController = null;
	let suggestionRequestId = 0;
	let movieController = null;
	let movieRequestId = 0;

	closeSuggestions();
	resultsContainer.replaceChildren();
	setStatus( statusElement, '' );
	updateButtonState( input, button );
	clearButton.hidden = '' === normalizeValue( input.value );

	function closeSuggestions() {
		suggestions = [];
		activeIndex = -1;
		listbox.hidden = true;
		listbox.replaceChildren();
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-activedescendant', '' );
	}

	function cancelSuggestionRequest() {
		window.clearTimeout( debounceTimer );
		suggestionRequestId += 1;

		if ( autocompleteController ) {
			autocompleteController.abort();
			autocompleteController = null;
		}

		if ( 'searching' === statusElement.dataset.state ) {
			setStatus( statusElement, '' );
		}
	}

	function cancelMovieRequest() {
		movieRequestId += 1;

		if ( movieController ) {
			movieController.abort();
			movieController = null;
		}

		setResultsBusy( resultsContainer, false );
	}

	function setActiveOption( index, isActive ) {
		const option = listbox.children[ index ];

		if ( ! option ) {
			return;
		}

		option.classList.toggle( 'is-active', isActive );
		option.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );

		if ( isActive ) {
			input.setAttribute( 'aria-activedescendant', option.id );
			option.scrollIntoView( { block: 'nearest' } );
		}
	}

	function updateActiveOption( nextIndex ) {
		if ( activeIndex === nextIndex ) {
			return;
		}

		if ( activeIndex >= 0 ) {
			setActiveOption( activeIndex, false );
		}

		activeIndex = nextIndex;

		if ( activeIndex >= 0 ) {
			setActiveOption( activeIndex, true );
			return;
		}

		input.setAttribute( 'aria-activedescendant', '' );
	}

	function renderSuggestions() {
		listbox.replaceChildren();

		if ( 0 === suggestions.length ) {
			closeSuggestions();
			return;
		}

		suggestions.forEach( ( suggestion, index ) => {
			listbox.appendChild(
				createOption( rootElement, suggestion, index )
			);
		} );

		listbox.hidden = false;
		input.setAttribute( 'aria-expanded', 'true' );
		input.setAttribute( 'aria-activedescendant', '' );
	}

	function moveActiveOption( offset ) {
		if ( 0 === suggestions.length ) {
			return;
		}

		let nextIndex = activeIndex + offset;

		if ( -1 === activeIndex ) {
			nextIndex = offset > 0 ? 0 : suggestions.length - 1;
		} else if ( nextIndex < 0 ) {
			nextIndex = suggestions.length - 1;
		} else if ( nextIndex >= suggestions.length ) {
			nextIndex = 0;
		}

		updateActiveOption( nextIndex );
	}

	async function loadMovie( params ) {
		cancelSuggestionRequest();
		closeSuggestions();

		if ( movieController ) {
			movieController.abort();
		}

		const requestId = ++movieRequestId;
		const controller = new window.AbortController();

		movieController = controller;

		setStatus( statusElement, '' );
		setInputState( input, false );
		setResultsBusy( resultsContainer, true );
		resultsContainer.replaceChildren( createResultSkeleton( rootElement ) );

		try {
			const movie = await apiFetch( {
				path: `/wp-movie-showcase/v1/movies?${ params }`,
				signal: controller.signal,
			} );

			if ( requestId !== movieRequestId ) {
				return;
			}

			resultsContainer.replaceChildren(
				createMovieMarkup( rootElement, movie )
			);
		} catch ( error ) {
			if ( error && 'AbortError' === error.name ) {
				return;
			}

			if ( requestId !== movieRequestId ) {
				return;
			}

			const message =
				error && 'wp_movie_showcase_not_found' === error.code
					? __( 'No movie found.', 'wp-movie-showcase' )
					: __(
							'Unable to complete the search. Please try again.',
							'wp-movie-showcase'
					  );

			renderMessage( resultsContainer, message );
			setStatus( statusElement, message, 'error' );
		} finally {
			if ( requestId === movieRequestId ) {
				setResultsBusy( resultsContainer, false );
			}

			if ( controller === movieController ) {
				movieController = null;
			}
		}
	}

	async function selectSuggestion( suggestion ) {
		input.value = normalizeValue( suggestion.title );
		updateButtonState( input, button );
		clearButton.hidden = false;
		await loadMovie(
			`imdb_id=${ encodeURIComponent( suggestion.imdb_id ) }`
		);
		input.focus();
	}

	function queueSuggestions() {
		const query = normalizeValue( input.value );

		cancelSuggestionRequest();
		setInputState( input, false );

		if ( query.length < MIN_QUERY_LENGTH ) {
			closeSuggestions();
			setStatus( statusElement, '' );
			return;
		}

		debounceTimer = window.setTimeout( async () => {
			const key = normalizeQuery( query );
			const requestId = ++suggestionRequestId;
			const isCached = suggestionCache.has( key );
			const controller = new window.AbortController();
			let statusTimerId = 0;

			autocompleteController = controller;

			if ( ! isCached ) {
				statusTimerId = window.setTimeout( () => {
					if ( requestId === suggestionRequestId ) {
						setStatus(
							statusElement,
							__( 'Searching…', 'wp-movie-showcase' ),
							'searching'
						);
					}
				}, SUGGESTION_STATUS_DELAY );
			}

			try {
				const items = await fetchSuggestions(
					query,
					controller.signal
				);

				if ( requestId !== suggestionRequestId ) {
					return;
				}

				suggestions = Array.isArray( items ) ? items.slice( 0, 5 ) : [];
				activeIndex = -1;

				if ( suggestions.length > 0 ) {
					setStatus(
						statusElement,
						sprintf(
							/* translators: %d: number of autocomplete suggestions. */
							__( '%d titles available.', 'wp-movie-showcase' ),
							suggestions.length
						),
						'results'
					);
					renderSuggestions();
					return;
				}

				closeSuggestions();
				setStatus(
					statusElement,
					__( 'No titles found.', 'wp-movie-showcase' ),
					'empty'
				);
			} catch ( error ) {
				if ( error && 'AbortError' === error.name ) {
					return;
				}

				if ( requestId !== suggestionRequestId ) {
					return;
				}

				closeSuggestions();
				setStatus(
					statusElement,
					__(
						'Unable to complete the search. Please try again.',
						'wp-movie-showcase'
					),
					'error'
				);
			} finally {
				window.clearTimeout( statusTimerId );

				if (
					requestId === suggestionRequestId &&
					'searching' === statusElement.dataset.state
				) {
					setStatus( statusElement, '' );
				}

				if ( controller === autocompleteController ) {
					autocompleteController = null;
				}
			}
		}, DEBOUNCE_DELAY );
	}

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();

		if (
			activeIndex >= 0 &&
			! listbox.hidden &&
			suggestions[ activeIndex ]
		) {
			await selectSuggestion( suggestions[ activeIndex ] );
			return;
		}

		const title = input.value.trim();

		if ( title.length < MIN_QUERY_LENGTH ) {
			setInputState( input, true );
			cancelSuggestionRequest();
			closeSuggestions();
			setStatus( statusElement, '' );
			return;
		}

		await loadMovie( `title=${ encodeURIComponent( title ) }` );
	} );

	input.addEventListener( 'input', () => {
		updateButtonState( input, button );
		clearButton.hidden = '' === normalizeValue( input.value );
		queueSuggestions();
	} );

	input.addEventListener( 'keydown', async ( event ) => {
		switch ( event.key ) {
			case 'ArrowDown':
				if ( suggestions.length > 0 ) {
					event.preventDefault();
					moveActiveOption( 1 );
				}
				break;
			case 'ArrowUp':
				if ( suggestions.length > 0 ) {
					event.preventDefault();
					moveActiveOption( -1 );
				}
				break;
			case 'Enter':
				if (
					activeIndex >= 0 &&
					! listbox.hidden &&
					suggestions[ activeIndex ]
				) {
					event.preventDefault();
					await selectSuggestion( suggestions[ activeIndex ] );
				}
				break;
			case 'Escape':
				closeSuggestions();
				setStatus( statusElement, '' );
				break;
			case 'Tab':
				closeSuggestions();
				break;
		}
	} );

	listbox.addEventListener( 'mousedown', ( event ) => {
		const option = event.target.closest( '.wp-movie-showcase__option' );

		if ( option ) {
			event.preventDefault();
		}
	} );

	listbox.addEventListener( 'click', async ( event ) => {
		const option = event.target.closest( '.wp-movie-showcase__option' );

		if ( ! option ) {
			return;
		}

		const suggestion = suggestions.find(
			( item ) => item.imdb_id === option.dataset.imdbId
		);

		if ( suggestion ) {
			await selectSuggestion( suggestion );
		}
	} );

	clearButton.addEventListener( 'click', () => {
		cancelSuggestionRequest();
		cancelMovieRequest();
		input.value = '';
		clearButton.hidden = true;
		updateButtonState( input, button );
		setInputState( input, false );
		closeSuggestions();
		setStatus( statusElement, '' );
		resultsContainer.replaceChildren();
		input.focus();
	} );

	rootElement.ownerDocument.addEventListener( 'click', ( event ) => {
		if ( ! rootElement.contains( event.target ) ) {
			closeSuggestions();
		}
	} );
}

function bindBlocks() {
	const blocks = document.querySelectorAll( '.wp-movie-showcase' );

	blocks.forEach( ( block ) => {
		bindBlock( block );
	} );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', bindBlocks );
} else {
	bindBlocks();
}
