=== Tainacan Metadata Crowdsource ===
Contributors: marcossigismundo
Tags: tainacan, crowdsourcing, metadata, museum, collections
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Permite que visitantes sugiram correções nos metadados dos itens Tainacan. As sugestões passam por moderação antes de serem aplicadas.

== Description ==

Tainacan Metadata Crowdsource adiciona uma camada de curadoria colaborativa às coleções do Tainacan. Visitantes (mesmo anônimos) podem sugerir correções para qualquer metadado de um item; a equipe revisa e aprova ou rejeita cada sugestão. Ao aprovar, o valor é gravado no item via as APIs oficiais do Tainacan (`Item_Metadata`), respeitando validação e cardinalidade.

Principais recursos:

* Exibição automática: um formulário recolhível é inserido ao final de cada página de item Tainacan (tema clássico), sem configuração por item. Pode ser desligado.
* Também disponível como shortcode `[tmc_suggest_form item_id="123"]` para colocação manual.
* Cada metadado é uma sugestão independente — o colaborador corrige só o que quiser.
* Painel de moderação no admin com filtros (pendentes, desatualizadas, aprovadas, rejeitadas).
* Detecção de sugestões "desatualizadas": se o valor original mudar antes da revisão, a sugestão é sinalizada.
* Anti-spam 100% local: verificação aritmética + honeypot + time-trap + rate-limit por IP. Sem dependência de serviços externos ou CDN.
* Notificação por e-mail ao moderador a cada nova sugestão.

Não depende de nenhum outro plugin além do próprio Tainacan.

== Installation ==

1. Faça upload da pasta `tainacan-metadata-crowdsource` para `/wp-content/plugins/`.
2. Ative o plugin em **Plugins → Instalados**.
3. Acesse **Crowdsource → Configurações** para definir o e-mail do moderador.
4. Insira `[tmc_suggest_form item_id="123"]` na página de um item (substitua `123` pelo ID).

== Frequently Asked Questions ==

= Preciso de uma conta em algum serviço de CAPTCHA? =

Não. A verificação anti-spam é local (uma soma simples + honeypot + janela mínima de tempo), sem terceiros e sem CDN.

= O plugin altera os itens diretamente? =

Só quando um moderador aprova uma sugestão. A gravação usa as APIs oficiais do Tainacan e passa pela validação do metadado.

= Funciona com itens de qualquer coleção? =

Sim. O formulário lê os metadados do item informado, independentemente da coleção.

== Changelog ==

= 1.0.0 =
* Versão inicial: submissão pública de sugestões, moderação no admin, detecção de "stale", anti-spam local e notificação por e-mail.

== Upgrade Notice ==

= 1.0.0 =
Versão inicial.
