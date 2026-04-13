# Tainacan Metadata Crowdsource

Plugin WordPress independente que permite aos visitantes sugerir correções nos metadados de itens do [Tainacan](https://tainacan.org). Sugestões são revisadas pela equipe antes de serem aplicadas.

## Como funciona

1. O administrador insere o shortcode `[tmc_suggest_form item_id="123"]` na página de um item (ou em um template).
2. O visitante (anônimo) vê cada metadado do item e pode sugerir um novo valor para qualquer campo.
3. Após confirmar o hCaptcha, a sugestão fica pendente no painel administrativo.
4. A equipe aprova ou rejeita. Ao aprovar, o valor sugerido é gravado no item Tainacan automaticamente.
5. Se o valor original mudar antes da revisão (ex: equipe editou o item), a sugestão é marcada como **desatualizada** para que o revisor reavalie.

## Requisitos

- WordPress 6.0+
- PHP 8.0+
- [Tainacan](https://tainacan.org) instalado e ativado
- Conta gratuita no [hCaptcha](https://www.hcaptcha.com) (site key + secret)

## Instalação

1. Copie a pasta para `wp-content/plugins/`
2. Ative em **Plugins → Instalados**
3. Configure as chaves hCaptcha em **Crowdsource → Configurações**

## Uso

```
[tmc_suggest_form item_id="123"]
```

Substitua `123` pelo ID do item Tainacan. O formulário renderiza automaticamente todos os metadados do item.

## Estrutura

```
tainacan-metadata-crowdsource/
├── tainacan-metadata-crowdsource.php   Bootstrap do plugin
├── uninstall.php                        Limpeza ao desinstalar
├── includes/
│   ├── Core/          Plugin, Activator, Deactivator
│   ├── Database/      Schema da tabela wp_tmc_suggestions
│   ├── REST/          Endpoints /wp-json/tmc/v1/*
│   ├── Frontend/      Shortcode [tmc_suggest_form]
│   ├── Admin/         Página de administração
│   └── SuggestionsManager.php   Lógica central de CRUD
└── assets/            CSS e JS (public + admin)
```

## Endpoints REST

| Método | Endpoint                                | Acesso  |
|--------|----------------------------------------|---------|
| POST   | `/wp-json/tmc/v1/suggestions`           | Público (com hCaptcha) |
| GET    | `/wp-json/tmc/v1/suggestions`           | `manage_options` |
| POST   | `/wp-json/tmc/v1/suggestions/{id}/approve` | `manage_options` |
| POST   | `/wp-json/tmc/v1/suggestions/{id}/reject`  | `manage_options` |

## Integração com Tainacan

O plugin usa as APIs oficiais `\Tainacan\Repositories\Items` e `\Tainacan\Repositories\Item_Metadata`. Não depende de nenhum outro plugin Tainacan (como o DIP Importer). Ao aprovar uma sugestão, o metadado é gravado via `Item_Metadata::insert()` respeitando validação e cardinalidade do Tainacan.

## Detecção de "stale"

Ao salvar qualquer post Tainacan (custom post type `tnc_col_*_item`), o plugin reavalia as sugestões pendentes desse item: se o hash do valor original mudou, a sugestão é marcada como **desatualizada**.

## Licença

GPL-3.0+
