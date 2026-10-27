/**
 * The CodeMirror 6 editor of the Markdown box: a document with a YAML
 * frontmatter and a GFM body, highlighted as such, wrapping long lines.
 *
 * CodeMirror 6 is a set of small packages assembled through extensions:
 * the state (document, selection, history), the view (DOM, keymaps,
 * gutters) and the languages (parsers that drive the highlighting). The
 * list below is the assembly; nothing here knows about WordPress or the
 * preview, which `mount.js` wires.
 */
import { EditorState } from '@codemirror/state'
import {
  EditorView,
  drawSelection,
  dropCursor,
  highlightActiveLine,
  highlightActiveLineGutter,
  highlightSpecialChars,
  keymap,
  lineNumbers,
} from '@codemirror/view'
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands'
import { bracketMatching, defaultHighlightStyle, indentOnInput, syntaxHighlighting } from '@codemirror/language'
import { highlightSelectionMatches, searchKeymap } from '@codemirror/search'
import { markdown, markdownLanguage } from '@codemirror/lang-markdown'
import { yamlFrontmatter } from '@codemirror/lang-yaml'

/**
 * @param {HTMLElement} parent        where the editor is appended
 * @param {string} initialValue
 * @param {object} callbacks
 * @param {(value: string) => void} callbacks.onChange  called with the whole document after each change
 */
export function createEditor(parent, initialValue, { onChange }) {
  const view = new EditorView({
    parent,
    state: EditorState.create({
      doc: initialValue,
      extensions: [
        lineNumbers(),
        highlightActiveLineGutter(),
        highlightSpecialChars(),
        history(),
        drawSelection(),
        dropCursor(),
        indentOnInput(),
        bracketMatching(),
        highlightActiveLine(),
        highlightSelectionMatches(),
        yamlFrontmatter({ content: markdown({ base: markdownLanguage }) }),
        syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
        EditorView.lineWrapping,
        // Tab indents (lists, code blocks); Escape then Tab leaves the editor.
        keymap.of([...defaultKeymap, ...historyKeymap, ...searchKeymap, indentWithTab]),
        EditorView.updateListener.of((update) => {
          if (update.docChanged) {
            onChange(update.state.doc.toString())
          }
        }),
      ],
    }),
  })

  return {
    getValue: () => view.state.doc.toString(),
    focus: () => view.focus(),
    destroy: () => view.destroy(),
  }
}
