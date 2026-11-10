@extends('errors.layout')

@php
  $message = isset($exception) ? $exception->getMessage() : '';
  $message = $message !== '' && $message !== 'This action is unauthorized.' ? $message : 'Nie masz uprawnień do tej strony.';
@endphp

@section('title', 'Brak dostępu')
@section('code', '403')
@section('heading', 'Brak dostępu')
@section('message', $message)
