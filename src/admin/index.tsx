/**
 * Event Hub admin — RSVP management screen.
 *
 * PHP (Admin_Page) renders <div id="event-hub-rsvps-root"> and inline
 * window.eventHubAdmin data; this React app takes it from there. Lists
 * RSVPs per event with status filter, two-step delete, pagination and a
 * CSV export link (admin-post.php, server-side nonce + capability).
 */
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import { Button, SelectControl, Spinner, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import {
	type EventHubAdminData,
	type RsvpRow,
	type RsvpsResponse,
	type StatusFilter,
} from './types';
import './styles/admin.scss';

const PER_PAGE = 50;

type ErrorState = string | null;

/** Read the boot data; fail loud if the page was loaded without it. */
function getBootData(): EventHubAdminData | null {
	return window.eventHubAdmin ?? null;
}

function AdminApp(): ReactElement {
	const boot = useMemo( getBootData, [] );
	const [ eventId, setEventId ] = useState< number >(
		boot?.initialEventId ?? 0
	);
	const [ status, setStatus ] = useState< StatusFilter >( '' );
	const [ page, setPage ] = useState( 1 );
	const [ rows, setRows ] = useState< RsvpRow[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< ErrorState >( null );
	const [ confirmingId, setConfirmingId ] = useState< number | null >( null );

	// No boot data: render a terminal error (PHP failed to inline it).
	if ( ! boot ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'Event Hub admin data is missing — reload the page.',
					'event-hub'
				) }
			</Notice>
		);
	}

	/* eslint-disable react-hooks/rules-of-hooks */
	useEffect( () => {
		if ( ! eventId ) {
			// Index state ("Select an event"): the REST contract rejects
			// eventId=0 with 400, so no request is sent — the prompt renders.
			setRows( [] );
			setTotal( 0 );
			setTotalPages( 1 );
			setError( null );
			setConfirmingId( null );
			setLoading( false );
			return;
		}

		let alive = true;
		setLoading( true );
		setError( null );
		setConfirmingId( null );

		const params = new URLSearchParams( {
			eventId: String( eventId ),
			page: String( page ),
			perPage: String( PER_PAGE ),
		} );
		if ( status ) {
			params.set( 'status', status );
		}

		fetch( `${ boot.restUrl }event-hub/v1/rsvps?${ params.toString() }`, {
			headers: { 'X-WP-Nonce': boot.nonce },
		} )
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				return response.json() as Promise< RsvpsResponse >;
			} )
			.then( ( data ) => {
				if ( ! alive ) {
					return;
				}
				setRows( data.rsvps ?? [] );
				setTotal( data.total ?? 0 );
				setTotalPages( Math.max( data.totalPages ?? 1, 1 ) );
			} )
			.catch( () => {
				if ( alive ) {
					setError(
						__(
							'Could not load RSVPs — please reload the page.',
							'event-hub'
						)
					);
				}
			} )
			.finally( () => {
				if ( alive ) {
					setLoading( false );
				}
			} );

		return () => {
			alive = false;
		};
	}, [ boot, eventId, status, page ] );
	/* eslint-enable react-hooks/rules-of-hooks */

	const deleteRsvp = async ( id: number ): Promise< void > => {
		setError( null );
		try {
			const response = await fetch(
				`${ boot.restUrl }event-hub/v1/rsvps/${ id }`,
				{
					method: 'DELETE',
					headers: { 'X-WP-Nonce': boot.nonce },
				}
			);
			if ( response.status === 404 ) {
				// Already gone — treat as success for the list.
				setRows( ( current ) =>
					current.filter( ( row ) => row.id !== id )
				);
				setTotal( ( current ) => Math.max( 0, current - 1 ) );
				return;
			}
			if ( ! response.ok ) {
				throw new Error( String( response.status ) );
			}
			setRows( ( current ) =>
				current.filter( ( row ) => row.id !== id )
			);
			setTotal( ( current ) => Math.max( 0, current - 1 ) );
		} catch {
			setError(
				__(
					'Could not delete the RSVP — please try again.',
					'event-hub'
				)
			);
		}
	};

	const eventOptions = [
		{ value: '0', label: __( 'Select an event', 'event-hub' ) },
		...boot.events.map( ( event ) => ( {
			value: String( event.id ),
			label: event.title || `#${ event.id }`,
		} ) ),
	];

	return (
		<div className="wrap event-hub-admin">
			<h1>{ __( 'Event Hub — RSVPs', 'event-hub' ) }</h1>
			{ error && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }
			<div className="event-hub-admin__toolbar">
				<SelectControl
					label={ __( 'Event', 'event-hub' ) }
					value={ String( eventId ) }
					onChange={ ( value ) => {
						setEventId( Number.parseInt( value, 10 ) || 0 );
						setPage( 1 );
					} }
					options={ eventOptions }
				/>
				<SelectControl
					label={ __( 'Status', 'event-hub' ) }
					value={ status }
					onChange={ ( value ) => {
						setStatus( value as StatusFilter );
						setPage( 1 );
					} }
					options={ [
						{ value: '', label: __( 'All', 'event-hub' ) },
						{ value: 'going', label: __( 'Going', 'event-hub' ) },
						{
							value: 'waitlist',
							label: __( 'Waitlist', 'event-hub' ),
						},
					] }
				/>
				<Button
					variant="primary"
					href={ boot.exportUrls[ eventId ] ?? undefined }
					disabled={ ! eventId }
				>
					{ __( 'Download CSV', 'event-hub' ) }
				</Button>
			</div>

			{ loading ? (
				<Spinner />
			) : (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col">{ __( 'Name', 'event-hub' ) }</th>
							<th scope="col">{ __( 'Email', 'event-hub' ) }</th>
							<th scope="col">{ __( 'Status', 'event-hub' ) }</th>
							<th scope="col">{ __( 'Date', 'event-hub' ) }</th>
							<th scope="col">
								<span className="screen-reader-text">
									{ __( 'Actions', 'event-hub' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ ! eventId && (
							<tr>
								<td colSpan={ 5 }>
									{ __(
										'Select an event to see its RSVPs.',
										'event-hub'
									) }
								</td>
							</tr>
						) }
						{ eventId && rows.length === 0 && (
							<tr>
								<td colSpan={ 5 }>
									{ __( 'No RSVPs yet.', 'event-hub' ) }
								</td>
							</tr>
						) }
						{ eventId &&
							rows.map( ( row ) => (
								<tr key={ row.id }>
									<td>{ row.name }</td>
									<td>{ row.email }</td>
									<td>{ row.status }</td>
									<td>
										{ new Date(
											row.createdAt
										).toLocaleString() }
									</td>
									<td>
										{ confirmingId === row.id ? (
											<span className="event-hub-admin__confirm">
												<Button
													isDestructive
													variant="primary"
													onClick={ () =>
														deleteRsvp( row.id )
													}
												>
													{ __(
														'Confirm delete',
														'event-hub'
													) }
												</Button>
												<Button
													variant="tertiary"
													onClick={ () =>
														setConfirmingId( null )
													}
												>
													{ __(
														'Cancel',
														'event-hub'
													) }
												</Button>
											</span>
										) : (
											<Button
												isDestructive
												variant="tertiary"
												onClick={ () =>
													setConfirmingId( row.id )
												}
											>
												{ __( 'Delete', 'event-hub' ) }
											</Button>
										) }
									</td>
								</tr>
							) ) }
					</tbody>
				</table>
			) }

			{ eventId && totalPages > 1 && (
				<div className="event-hub-admin__pagination">
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( ( p ) => p - 1 ) }
					>
						{ __( 'Previous', 'event-hub' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: total pages, 3: total RSVPs. */
							__( 'Page %1$d of %2$d (%3$d total)', 'event-hub' ),
							page,
							totalPages,
							total
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= totalPages }
						onClick={ () => setPage( ( p ) => p + 1 ) }
					>
						{ __( 'Next', 'event-hub' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

const root = document.getElementById( 'event-hub-rsvps-root' );
if ( root ) {
	createRoot( root ).render( <AdminApp /> );
}
