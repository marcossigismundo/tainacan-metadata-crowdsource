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
 * CAPTCHA local e stateless — sem dependência de terceiros, script externo
 * ou escrita no banco (importante: o desafio é gerado num endpoint público).
 *
 * Três camadas combinadas:
 *  1. Pergunta aritmética simples. O token carrega os operandos e o timestamp
 *     assinados por HMAC (wp_salt), então o servidor confia neles sem guardar
 *     estado. Sem transient = sem vetor de DoS por inflar wp_options.
 *  2. Honeypot: campo oculto que humanos não preenchem.
 *  3. Time-trap: submissão fora da janela [MIN_SECONDS, TTL] é tratada como bot.
 */
class Captcha {

	const MIN_SECONDS = 3;   // Tempo mínimo plausível de preenchimento humano.
	const TTL         = 600; // Validade do desafio (10 min).

	/**
	 * Gera um novo desafio.
	 *
	 * @return array{token:string,question:string} Token assinado e expressão (ex.: "3 + 5").
	 */
	public static function generate() {
		$a       = wp_rand( 1, 9 );
		$b       = wp_rand( 1, 9 );
		$created = time();
		$payload = $a . '|' . $b . '|' . $created;

		// Token = "a|b|created|hmac" — só dígitos, "|" e hex (seguro em JSON).
		return array(
			'token'    => $payload . '|' . self::sign( $payload ),
			'question' => $a . ' + ' . $b,
		);
	}

	/**
	 * Verifica a resposta contra o token assinado.
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

		$parts = explode( '|', (string) $token );
		if ( 4 !== count( $parts ) ) {
			return false;
		}

		list( $a, $b, $created, $sig ) = $parts;
		$payload                       = $a . '|' . $b . '|' . $created;

		// Assinatura inválida => token forjado/adulterado.
		if ( ! hash_equals( self::sign( $payload ), (string) $sig ) ) {
			return false;
		}

		// Time-trap: rápido demais (bot) ou expirado.
		$elapsed = time() - (int) $created;
		if ( $elapsed < self::MIN_SECONDS || $elapsed > self::TTL ) {
			return false;
		}

		return is_numeric( $answer ) && ( (int) $a + (int) $b ) === (int) $answer;
	}

	/**
	 * Assina um payload com o segredo do site (wp_salt).
	 *
	 * @param string $payload Operandos e timestamp.
	 * @return string
	 */
	private static function sign( $payload ) {
		return hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
	}
}
