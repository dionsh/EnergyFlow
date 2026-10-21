import { Fragment } from 'react'

/**
 * The small markdown subset the assistant writes: paragraphs, "- " and "1. "
 * lists, **bold**, _italic_ and `code`, plus what a model writes now and then
 * against instructions (### headings, ``` blocks, | tables |), shown plainly.
 * Rendered as React elements — never as HTML — so nothing in an answer can
 * inject markup.
 */
export function MarkdownLite({ text }) {
  const blocks = []
  let list = null
  let code = null
  for (const raw of String(text ?? '').split('\n')) {
    const line = raw.trimEnd()
    if (line.trimStart().startsWith('```')) {
      if (code) code = null
      else {
        code = { type: 'pre', lines: [] }
        blocks.push(code)
      }
      list = null
      continue
    }
    if (code) {
      code.lines.push(raw)
      continue
    }
    const heading = line.match(/^\s*#{1,6}\s+(.*)$/)
    if (heading) {
      list = null
      blocks.push({ type: 'h', text: heading[1] })
      continue
    }
    if (/^\s*\|.*\|\s*$/.test(line)) {
      // A table: rows kept aligned and scrollable instead of overflowing the panel.
      list = null
      if (/^\s*\|[\s:|-]+\|\s*$/.test(line)) continue
      const last = blocks[blocks.length - 1]
      const cells = line.trim().slice(1, -1).split('|').map((c) => c.trim())
      if (last?.type === 'table') last.rows.push(cells)
      else blocks.push({ type: 'table', rows: [cells] })
      continue
    }
    const bullet = line.match(/^\s*[-*•]\s+(.*)$/)
    const numbered = line.match(/^\s*(\d+)[.)]\s+(.*)$/)
    if (bullet || numbered) {
      const ordered = Boolean(numbered)
      if (!list || list.ordered !== ordered) {
        list = { type: 'list', ordered, items: [] }
        blocks.push(list)
      }
      list.items.push(bullet ? bullet[1] : numbered[2])
      continue
    }
    list = null
    if (line.trim() === '') {
      blocks.push({ type: 'break' })
    } else {
      const last = blocks[blocks.length - 1]
      if (last?.type === 'p') last.lines.push(line.trim())
      else blocks.push({ type: 'p', lines: [line.trim()] })
    }
  }

  return (
    <div className="flex flex-col gap-2">
      {blocks.filter((b) => b.type !== 'break').map((block, index) => {
        if (block.type === 'pre') {
          return (
            <pre key={index} className="overflow-x-auto rounded-md bg-surface-2 px-3 py-2 font-mono text-[12px] leading-relaxed [overflow-wrap:normal]">
              {block.lines.join('\n')}
            </pre>
          )
        }
        if (block.type === 'h') {
          return <p key={index} className="font-semibold text-ink"><Inline text={block.text} /></p>
        }
        if (block.type === 'table') {
          return (
            <div key={index} className="overflow-x-auto">
              <table className="w-full border-collapse text-[12.5px] [overflow-wrap:normal]">
                <tbody>
                  {block.rows.map((row, r) => (
                    <tr key={r} className={r === 0 ? 'border-b border-line-strong font-medium' : 'border-b border-line'}>
                      {row.map((cell, c) => <td key={c} className="px-2 py-1 align-top first:pl-0"><Inline text={cell} /></td>)}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        }
        if (block.type === 'list') {
          const Tag = block.ordered ? 'ol' : 'ul'
          return (
            <Tag key={index} className={block.ordered ? 'flex list-decimal flex-col gap-1 pl-5' : 'flex list-disc flex-col gap-1 pl-5 marker:text-ink-3'}>
              {block.items.map((item, i) => <li key={i}><Inline text={item} /></li>)}
            </Tag>
          )
        }
        return (
          <p key={index}>
            {block.lines.map((line, i) => (
              <Fragment key={i}>
                {i > 0 && <br />}
                <Inline text={line} />
              </Fragment>
            ))}
          </p>
        )
      })}
    </div>
  )
}

function Inline({ text }) {
  const parts = []
  // **bold** · _italic_ or *italic* (not "5 * 3") · `code`
  const pattern = /\*\*(.+?)\*\*|(?<![\p{L}\d])_(.+?)_(?![\p{L}\d])|(?<![*\p{L}\d])\*(?!\s)(.+?)(?<!\s)\*(?![*\p{L}\d])|`([^`]+)`/gu
  let last = 0
  for (const match of text.matchAll(pattern)) {
    if (match.index > last) parts.push(text.slice(last, match.index))
    if (match[1] !== undefined) parts.push(<strong key={match.index} className="font-semibold text-ink">{match[1]}</strong>)
    else if (match[2] !== undefined || match[3] !== undefined) parts.push(<em key={match.index} className="text-ink-2">{match[2] ?? match[3]}</em>)
    else parts.push(<code key={match.index} className="rounded-sm bg-surface-2 px-1 font-mono text-[12px]">{match[4]}</code>)
    last = match.index + match[0].length
  }
  if (last < text.length) parts.push(text.slice(last))
  return parts
}
