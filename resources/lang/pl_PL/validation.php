<?php

/*
|--------------------------------------------------------------------------
| Fill Me In — komunikaty walidacji (Laravel Validator)
|--------------------------------------------------------------------------
|
| Używane na trasach aplikacji (locale ustawiane przez middleware fmi.app).
| Brakujące reguły spadają do angielskich komunikatów Laravela — dopisuj w miarę potrzeb.
|
*/

return [
	'accepted' => 'Pole :attribute musi zostać zaakceptowane.',
	'after' => 'Pole :attribute musi być datą późniejszą niż :date.',
	'array' => 'Pole :attribute musi być listą.',
	'confirmed' => 'Pole :attribute nie zgadza się z potwierdzeniem.',
	'different' => 'Pole :attribute musi się różnić od pola :other.',
	'boolean' => 'Pole :attribute musi mieć wartość tak lub nie.',
	'date' => 'Pole :attribute nie jest poprawną datą.',
	'distinct' => 'Pole :attribute zawiera powtórzoną wartość.',
	'email' => 'Pole :attribute musi być poprawnym adresem e-mail.',
	'exists' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
	'in' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
	'integer' => 'Pole :attribute musi być liczbą całkowitą.',
	'max' => [
		'array' => 'Pole :attribute może zawierać maksymalnie :max elementów.',
		'numeric' => 'Pole :attribute nie może być większe niż :max.',
		'string' => 'Pole :attribute może mieć maksymalnie :max znaków.',
	],
	'min' => [
		'array' => 'Pole :attribute musi zawierać co najmniej :min elementów.',
		'numeric' => 'Pole :attribute musi wynosić co najmniej :min.',
		'string' => 'Pole :attribute musi mieć co najmniej :min znaków.',
	],
	'numeric' => 'Pole :attribute musi być liczbą.',
	'regex' => 'Format pola :attribute jest nieprawidłowy.',
	'required' => 'Pole :attribute jest wymagane.',
	'string' => 'Pole :attribute musi być tekstem.',
	'url' => 'Pole :attribute musi być poprawnym adresem URL.',

	'attributes' => [
		'current_password' => 'aktualne hasło',
	],
];
