/**
 * Creates an element; children are appended as nodes or as text, never as HTML.
 */
export function el(tagName, attributes = {}, children = []) {
  const element = document.createElement(tagName);
  for (const [name, value] of Object.entries(attributes)) {
    element.setAttribute(name, value);
  }
  for (const child of Array.isArray(children) ? children : [children]) {
    element.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return element;
}
