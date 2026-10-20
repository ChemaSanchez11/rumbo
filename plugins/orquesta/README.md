# orquesta

Plugin de Claude Code que reparte cada tarea entre varios modelos a la vez mediante subagentes:

| Rol | Modelo | Hace |
|---|---|---|
| `analista` | Sonnet | Lee código, planifica y documenta |
| `disenador` | Opus | Diseño de la aplicación/web (sistema visual, UX, accesibilidad) |
| `desarrollador` | Opus | Desarrollo frontend y backend |
| `operador` | Haiku | git (commit, push…) y comandos básicos |

La sesión principal actúa de orquestador: planifica con el analista, diseña con el diseñador, programa con el desarrollador (dos instancias en paralelo si frontend y backend son independientes), documenta con el analista y sube a git con el operador.

## Instalación

Desde Claude Code:

```
/plugin marketplace add ChemaSanchez11/rumbo
/plugin install orquesta@rumbo-plugins
```

O en local, apuntando a esta carpeta:

```
claude --plugin-dir ./plugins/orquesta
```

## Uso

- **Comando**: `/orquesta:equipo añade un filtro por tipo de combustible en el mapa`
- **Automático**: la skill `orquestar` se activa sola cuando pides construir o cambiar algo.
- **Un rol concreto**: «usa el operador para subir los cambios», «pídele al analista que documente la API».

Para ahorrar, la sesión principal (el orquestador) puede ir con un modelo más ligero (`/model sonnet`); el trabajo pesado lo hacen los subagentes con su propio modelo.

## Personalizar

Cada rol es un archivo en `agents/`. Cambia el campo `model:` (`sonnet`, `opus`, `haiku` o `inherit`) o la lista `tools:` para ajustar qué modelo usa y qué puede hacer.
