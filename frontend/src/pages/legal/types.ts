export type LegalSection = {
  title: string
  paragraphs: string[]
  bullets?: string[]
}

export type LegalDocument = {
  title: string
  updatedAt: string
  intro: string
  sections: LegalSection[]
  contactNote: string
}
