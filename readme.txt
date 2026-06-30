=== Tainacan Metadata Crowdsource ===
Contributors: marcossigismundo
Tags: tainacan, crowdsourcing, metadata, museum, collections
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.5.3
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
* Controle por coleção: escolha quais metadados de cada coleção podem receber sugestões (allowlist), com opção de desativar o crowdsourcing em coleções específicas.

Não depende de nenhum outro plugin além do próprio Tainacan.

== Installation ==

1. Faça upload da pasta `tainacan-metadata-crowdsource` para `/wp-content/plugins/`.
2. Ative o plugin em **Plugins → Instalados**.
3. Acesse **Crowdsource → Configurações** para definir o e-mail do moderador.
4. (Opcional) Em **Crowdsource → Coleções**, escolha quais metadados de cada coleção aceitam sugestões.
5. Insira `[tmc_suggest_form item_id="123"]` na página de um item (substitua `123` pelo ID), ou deixe a exibição automática ligada.

== Frequently Asked Questions ==

= Preciso de uma conta em algum serviço de CAPTCHA? =

Não. A verificação anti-spam é local (uma soma simples + honeypot + janela mínima de tempo), sem terceiros e sem CDN.

= O plugin altera os itens diretamente? =

Só quando um moderador aprova uma sugestão. A gravação usa as APIs oficiais do Tainacan e passa pela validação do metadado.

= Funciona com itens de qualquer coleção? =

Sim. Por padrão o formulário lê todos os metadados públicos do item. Em **Crowdsource → Coleções** você pode, para cada coleção, escolher exatamente quais metadados aceitam sugestões — ou desativar o crowdsourcing naquela coleção.

= Coleções já existentes mudam de comportamento ao atualizar? =

Não. Enquanto uma coleção não for configurada na aba "Coleções", ela continua aceitando sugestões em todos os metadados públicos, como antes. A allowlist só passa a valer depois que você salva a configuração daquela coleção.

== Changelog ==

= 1.5.3 =
* Correção visual: o título do modal força a cor branca (`!important`), evitando que o estilo de cabeçalhos (h3) do tema o deixe ilegível sobre o degradê.

= 1.5.2 =
* Cores alinhadas à identidade do site: o cabeçalho do modal e os botões agora usam o degradê oficial teal (petróleo) → verde, em vez do verde genérico. Título e botão de fechar em branco sobre o degradê, com bom contraste.

= 1.5.1 =
* Visual: novo tema verde claro moderno, com cabeçalho e botões em degradê e fonte do sistema mais legível (com suavização).
* O aviso "um valor por linha" virou um selo de destaque (badge) ao lado do nome do campo.
* O botão de fechar (×) agora tem cor: círculo verde claro que fica verde sólido ao passar o mouse.

= 1.5.0 =
* Modal redesenhado: diálogo focado e moderno (mais estreito e centralizado, cantos arredondados, sombra e foco suaves, botão em pílula), em vez do painel largo de duas colunas. Em telas pequenas o modal sobe de baixo, ocupando a largura total.
* Ordem dos metadados: os campos passam a seguir exatamente a ordem configurada na coleção do Tainacan (a mesma exibida na página do item). A antiga grade de duas colunas reposicionava os campos visualmente; agora é uma lista de coluna única.
* Removido o campo "Descrição da imagem" do formulário: na prática ele duplicava o metadado "Âmbito e conteúdo". A revisão de sugestões de descrição já existentes no banco continua funcionando.

= 1.4.4 =
* Correção: a 1.4.3 (rolagem natural) tirava a barra de rolagem e travava a página, porque o Tainacan define `html { overflow: hidden }` para forçar o scroll interno dos seus containers. Agora a rolagem vertical da janela é destravada nas páginas do plugin, devolvendo a barra de rolagem e o acesso ao fim do conteúdo. Validado em navegador headless.

= 1.4.3 =
* Correção (definitiva): a aba "Coleções" não rolava até o fim. Causa real: os containers de altura fixa do Tainacan (`100vh`) somados a avisos do WordPress (ex.: "nova versão disponível") empurravam o conteúdo para além da viewport, e a rolagem aninhada não alcançava o fim. Agora, nas páginas do plugin, as alturas/overflow fixos são neutralizados e a rolagem passa a ser a natural do navegador, com a barra lateral do Tainacan fixa. (O `flex-shrink` da 1.4.2 não bastava.) Validado medindo o alcance da rolagem em navegador headless.

= 1.4.2 =
* Correção parcial (insuficiente; ver 1.4.3): tentativa de impedir o encolhimento do container de conteúdo no layout flex do Tainacan (`flex-shrink: 0`).

= 1.4.1 =
* Correção: a seleção de metadados por coleção não era salva na primeira gravação (ao recarregar, todos voltavam marcados). Causa: ao criar a option, o WordPress aplica o saneamento duas vezes (update_option → add_option) e a segunda passada recebia o valor já normalizado, sem o marcador do formulário, zerando a configuração. O saneamento agora é idempotente.

= 1.4.0 =
* Controle por coleção: nova aba "Coleções" no painel permite escolher quais metadados de cada coleção podem receber sugestões (allowlist), além do campo "Descrição da imagem", e desativar o crowdsourcing em coleções específicas. Cada coleção tem seu próprio formulário (salvar uma não afeta as demais) e atalhos "Selecionar todos / Nenhum".
* Compatibilidade: coleções ainda não configuradas mantêm o comportamento anterior (todos os metadados públicos liberados); a allowlist só vale após salvar a coleção.
* Segurança: submissões para um metadado não habilitado na coleção são rejeitadas no servidor, mesmo via requisição forjada.

= 1.3.0 =
* Exclusão de sugestões: botão "Excluir" por sugestão e "Excluir submissão" (apaga todas as sugestões de um envio), com confirmação.
* Histórico de revisão: sugestões já avaliadas mostram quem aprovou/rejeitou, quando, se o valor foi editado pelo gestor e as notas da revisão.

= 1.2.2 =
* Correção: a página de moderação não rolava até o fim. O container de conteúdo deixa de ter altura fixa com rolagem interna aninhada; a rolagem passa para o container do Tainacan, e a lista chega ao fim normalmente.

= 1.2.1 =
* Segurança: o formulário público deixa de oferecer metadados não-públicos (privado/rascunho), que poderiam vazar valores restritos; submissões para metadados não-públicos são rejeitadas no servidor mesmo via requisição forjada.

= 1.2.0 =
* Edição estilo enciclopédia colaborativa: o visitante edita o texto diretamente no campo (pré-preenchido com o valor atual); só os campos alterados são enviados. Multivalorados são editados um valor por linha, com botão de restaurar o original.
* Curadoria do gestor: na moderação, o valor sugerido é editável e o gestor pode complementar antes de aprovar; o valor curado é aplicado e auditado (colunas final_value, edited_by).
* Diferenças destacadas (atual × sugerida) na moderação, via wp_text_diff do WordPress.

= 1.1.1 =
* Correção de layout na página de moderação: o primeiro card deixava de alinhar (a linha de filtros era flutuante) e o fim da lista ficava cortado dentro do container do Tainacan.
* Tabela de sugestões com colunas proporcionais (layout fixo) para descrições longas.

= 1.1.0 =
* Formulário em modal (metadados em duas colunas) na página do item, via hook do tema Tainacan Interface.
* Novo campo "Descrição da imagem" (quando o item tem documento), aplicável ao conteúdo do item.
* Moderação agrupada por submissão: cada metadado é aceito ou rejeitado separadamente.
* Mensagem de agradecimento por e-mail ao colaborador após a avaliação.
* Segurança: CAPTCHA stateless (sem escrita no banco em endpoint público), validação de item Tainacan publicado, limite de envios por hora, limite de tamanho do valor e gate de permissão (manage_options) para visualizar dados pessoais.

= 1.0.0 =
* Versão inicial: submissão pública de sugestões, moderação no admin, detecção de "stale", anti-spam local e notificação por e-mail.

== Upgrade Notice ==

= 1.1.0 =
Modal na página do item, campo de descrição da imagem, moderação por submissão com agradecimento e endurecimento de segurança.

= 1.0.0 =
Versão inicial.
