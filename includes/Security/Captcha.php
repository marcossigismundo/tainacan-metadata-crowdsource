<?php
/**
 * CAPTCHA local (sem terceiros) para o formulário público.
 *
 * @package TMC
 */

namespace TMC\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CAPTCHA local — sem dependência de terceiros nem script externo (zero CDN).
 *
 * Três camadas combinadas:
 *  1. Pergunta aritmética simples: token + resposta esperada guardados em
 *     transient, de uso único (consumido na verificação).
 *  2. Honeypot: campo oculto que humanos não preenchem; se vier preenchido,
 *     é bot.
 *  3. Time-trap: submissão em menos de MIN_SECONDS é tratada como bot.
 *
 * O desafio é entregue via REST (GET /captcha) e não embutido no HTML, para
 * ser imune a cache de página (page cache serviria o mesmo token a todos).
 */
class Captcha {

	const TRANSIENT_PREFIX = 'tmc_cap_';
	const TTL              = 600; // Segundos de validade do desafio (10 min).
	const MIN_SECONDS      = 3;   // Tempo mínimo plausível de preenchimento humano.

	/**
	 * Gera um novo desafio.
	 *
	 * @return array{token:string,question:string} Token e expressão (ex.: "3 + 5").
	 */
	public static function generate() {
		$a     = wp_rand( 1, 9 );
		$b     = wp_rand( 1, 9 );
		$token = wp_generate_password( 24, false, false );

		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'answer'  => $a + $b,
				'created' => time(),
			),
			self::TTL
		);

		return array(
			'token'    => $token,
			'question' => $a . ' + ' . $b,
		);
	}

	/**
	 * Verifica a resposta. Consome o token (uso único).
	 *
	 * @param string $token    Token devolvido por generate().
	 * @param mixed  $answer   Resposta numérica informada pelo usuário.
	 * @param string $honeypot Conteúdo do campo honeypot (deve ser vazio).
	 * @return bool
	 */
	public static function verify( $token, $answer, $honeypot = '' ) {
		// Honeypot preenchido => bot.
		if ( '' !== trim( (string) $honeypot ) ) {
			return false;
		}

		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( '' === $token ) {
			return false;
		}

		$key    = self::TRANSIENT_PREFIX . $token;
		$stored = get_transient( $key );
		delete_transient( $key ); // Uso único, mesmo em caso de falha.

		if ( ! is_array( $stored ) || ! isset( $stored['answer'], $stored['created'] ) ) {
			return false;
		}

		// Time-trap: rápido demais para ser humano.
		if ( ( time() - (int) $stored['created'] ) < self::MIN_SECONDS ) {
			return false;
		}

		return is_numeric( $answer ) && (int) $answer === (int) $stored['answer'];
	}
}
