import { Children, useCallback, useLayoutEffect, useRef, useState, type ReactNode } from 'react'

/**
 * Packs cards into columns with no gaps: each card, in order, goes to the currently shortest column.
 * The column count follows the container width (`minWidth` per column). Placement is derived during
 * render from measured card heights, so it is always consistent with the current column count.
 */
export function Masonry({ children, minWidth = 320, gap = 14 }: { children: ReactNode; minWidth?: number; gap?: number }) {
  const items = Children.toArray(children)
  const [columns, setColumns] = useState(1)
  const [heights, setHeights] = useState<number[]>([])
  const nodes = useRef(new Map<Element, number>())
  const observer = useRef<ResizeObserver | null>(null)

  // One observer for the container (column count) and every card (heights).
  const box = useCallback((el: HTMLDivElement | null) => {
    observer.current?.disconnect()
    if (!el) return
    const ro = new ResizeObserver(() => {
      setColumns(Math.max(1, Math.floor((el.clientWidth + gap) / (minWidth + gap))))
      setHeights((cur) => {
        const next = [...cur]
        nodes.current.forEach((i, node) => { next[i] = (node as HTMLElement).offsetHeight })
        return next.length === cur.length && next.every((h, i) => h === cur[i]) ? cur : next
      })
    })
    ro.observe(el)
    nodes.current.forEach((_, node) => ro.observe(node))
    observer.current = ro
  }, [minWidth, gap])

  const cell = (i: number) => (el: HTMLDivElement | null) => {
    if (el) {
      nodes.current.set(el, i)
      observer.current?.observe(el)
      return () => {
        nodes.current.delete(el)
        observer.current?.unobserve(el)
      }
    }
  }

  useLayoutEffect(() => () => observer.current?.disconnect(), [])

  // Greedy placement: next card into the shortest column.
  const totals = Array(columns).fill(0)
  const colOf = items.map((_, i) => {
    const col = totals.indexOf(Math.min(...totals))
    totals[col] += (heights[i] ?? 0) + gap
    return col
  })

  return (
    <div ref={box} style={{ display: 'grid', gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`, gap, alignItems: 'start' }}>
      {Array.from({ length: columns }, (_, col) => (
        <div key={col} style={{ display: 'flex', flexDirection: 'column', gap, minWidth: 0 }}>
          {items.map((item, i) => (colOf[i] === col ? <div key={i} ref={cell(i)} style={{ display: 'flex', flexDirection: 'column' }}>{item}</div> : null))}
        </div>
      ))}
    </div>
  )
}
