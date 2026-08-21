# Is the Remainder subset check only in the page, or does Add word send pins to the server?

Type: grilling
Map: [Remainder explorer spec](../map.md)

## Question

Is the Remainder subset check only enforced on Target show, or must Add word send the pin set so the server can reject a word that does not fit Remainder?

Pins are a Target-show choice, not Target progress today. Add word currently adds a catalog word and resumes Target search with no notion of a pin set. Decide whether Remainder is UI-only (client gate, API unchanged) or a request the server must honor.
